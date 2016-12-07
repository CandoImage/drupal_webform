<?php

namespace Drupal\webform\Plugin\WebformHandler;

use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Webform submission remote post handler.
 *
 * @WebformHandler(
 *   id = "remote_post",
 *   label = @Translation("Remote post"),
 *   category = @Translation("External"),
 *   description = @Translation("Posts webform submissions to a URL."),
 *   cardinality = \Drupal\webform\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class RemotePostWebformHandler extends WebformHandlerBase {

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger, ClientInterface $http_client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger);
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')->get('webform.remote_post'),
      $container->get('http_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    $configuration = $this->getConfiguration();

    // If the saving of results is disabled clear update and delete URL.
    if ($this->getWebform()->getSetting('results_disabled')) {
      $configuration['settings']['update_url'] = '';
      $configuration['settings']['delete_url'] = '';
    }

    return [
      '#settings' => $configuration['settings'],
    ] + parent::getSummary();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $field_names = array_keys(\Drupal::service('entity_field.manager')->getBaseFieldDefinitions('webform_submission'));
    $excluded_data = array_combine($field_names, $field_names);
    return [
      'type' => 'x-www-form-urlencoded',
      'insert_url' => '',
      'update_url' => '',
      'delete_url' => '',
      'excluded_data' => $excluded_data,
      'debug' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $webform = $this->getWebform();
    $results_disabled = $webform->getSetting('results_disabled');

    $form['insert_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Insert URL'),
      '#description' => $this->t('The full URL to POST to when a new webform submission is saved. E.g. http://www.mycrm.com/form_insert_handler.php'),
      '#required' => TRUE,
      '#default_value' => $this->configuration['insert_url'],
    ];

    $form['update_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Update URL'),
      '#description' => $this->t('The full URL to POST to when an existing webform submission is updated. E.g. http://www.mycrm.com/form_insert_handler.php'),
      '#default_value' => $this->configuration['update_url'],
      '#access' => !$results_disabled,
    ];

    $form['delete_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Save URL'),
      '#description' => $this->t('The full URL to POST to call when a webform submission is deleted. E.g. http://www.mycrm.com/form_delete_handler.php'),
      '#default_value' => $this->configuration['delete_url'],
      '#access' => !$results_disabled,
    ];

    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Post type'),
      '#description' => $this->t('Use x-www-form-urlencoded if unsure, as it is the default format for HTML webforms. You also have the option to post data in <a href="http://www.json.org/" target="_blank">JSON</a> format.'),
      '#options' => [
        'x-www-form-urlencoded' => $this->t('x-www-form-urlencoded'),
        'json' => $this->t('JSON'),
      ],
      '#required' => TRUE,
      '#default_value' => $this->configuration['type'],
    ];

    $form['post_data'] = [
      '#type' => 'details',
      '#title' => $this->t('Posted data'),
    ];
    $form['post_data']['excluded_data'] = [
      '#type' => 'webform_excluded_columns',
      '#title' => $this->t('Posted data'),
      '#title_display' => 'invisible',
      '#webform' => $webform,
      '#required' => TRUE,
      '#parents' => ['settings', 'excluded_data'],
      '#default_value' => $this->configuration['excluded_data'],
    ];

    $form['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debugging'),
      '#description' => $this->t('If checked, posted submissions will be displayed onscreen to all users.'),
      '#return_value' => TRUE,
      '#default_value' => $this->configuration['debug'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValues();
    foreach ($this->configuration as $name => $value) {
      if (isset($values[$name])) {
        $this->configuration[$name] = $values[$name];
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $operation = ($update) ? 'update' : 'insert';
    $this->remotePost($operation, $webform_submission);
  }

  /**
   * {@inheritdoc}
   */
  public function postDelete(WebformSubmissionInterface $webform_submission) {
    $this->remotePost('delete', $webform_submission);
  }

  /**
   * Execute a remote post.
   *
   * @param string $operation
   *   The type of webform submission operation to be posted. Can be 'insert',
   *   'update', or 'delete'.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission to be posted.
   */
  protected function remotePost($operation, WebformSubmissionInterface $webform_submission) {
    $request_url = $this->configuration[$operation . '_url'];
    if (empty($request_url)) {
      return;
    }

    $request_type = $this->configuration['type'];
    $request_post_data = $this->getPostData($webform_submission);

    try {
      switch ($request_type) {
        case 'json':
          $response = $this->httpClient->post($request_url, ['json' => $request_post_data]);
          break;

        case 'x-www-form-urlencoded':
        default:
          $response = $this->httpClient->post($request_url, ['form_params' => $request_post_data]);
          break;
      }
    }
    catch (RequestException $request_exception) {
      $message = $request_exception->getMessage();
      $response = $request_exception->getResponse();

      // If debugging is enabled, display the error message on screen.
      $this->debug($message, $operation, $request_url, $request_type, $request_post_data, $response, 'error');

      // Log error message.
      $context = [
        '@form' => $this->getWebform()->label(),
        '@operation' => $operation,
        '@type' => $request_type,
        '@url' => $request_url,
        '@message' => $message,
        'link' => $this->getWebform()->toLink(t('Edit'), 'handlers-form')->toString(),
      ];
      $this->logger->error('@form webform remote @type post (@operation) to @url failed. @message', $context);
      return;
    }

    // If debugging is enabled, display the request and response.
    $this->debug(t('Remote post successful!'), $operation, $request_url, $request_type, $request_post_data, $response, 'warning');
  }

  /**
   * Get a webform submission's post data.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   A webform submission.
   *
   * @return array
   *   A webform submission converted to an associative array.
   */
  protected function getPostData(WebformSubmissionInterface $webform_submission) {
    // Get submission and elements data.
    $data = $webform_submission->toArray(TRUE);

    // Flatten data.
    // Prioritizing elements before the submissions fields.
    $data = $data['data'] + $data;
    unset($data['data']);

    // Excluded selected data.
    $data = array_diff_key($data, $this->configuration['excluded_data']);

    return $data;
  }

  /**
   * Display debugging information.
   *
   * @param string $message
   *   Message to be displayed.
   * @param string $operation
   *   The operation being performed, can be either insert, update, or delete.
   * @param string $request_url
   *   The remote URL the request is being posted to.
   * @param string $request_type
   *   The type of remote post.
   * @param string $request_post_data
   *   The webform submission data being posted.
   * @param \Psr\Http\Message\ResponseInterface|null $response
   *   The response returned by the remote server.
   * @param string $type
   *   The type of message to be displayed to the end use.
   */
  protected function debug($message, $operation, $request_url, $request_type, $request_post_data, ResponseInterface $response = NULL, $type = 'warning') {
    if (empty($this->configuration['debug'])) {
      return;
    }

    $build = [];

    // Message.
    $build['message'] = [
      '#markup' => $message,
      '#prefix' => '<b>',
      '#suffix' => '</b>',
    ];

    // Operation.
    $build['operation'] = [
      '#type' => 'item',
      '#title' => $this->t('Remote operation'),
      '#markup' => $operation,
    ];

    // Request.
    $build['request_url'] = [
      '#type' => 'item',
      '#title' => $this->t('Request URL'),
      '#markup' => $request_url,
    ];
    $build['request_type'] = [
      '#type' => 'item',
      '#title' => $this->t('Request type'),
      '#markup' => $request_type,
    ];
    $build['request_post_data'] = [
      '#type' => 'details',
      '#title' => $this->t('Request post data'),
      'data' => [
        '#markup' => htmlspecialchars(Yaml::encode($request_post_data)),
        '#prefix' => '<pre>',
        '#suffix' => '</pre>',
      ],
    ];

    $build['returned'] = [
      '#markup' => $this->t('...returned...'),
      '#prefix' => '<b>',
      '#suffix' => '</b>',
    ];

    // Response.
    if ($response) {
      $build['response_code'] = [
        '#type' => 'item',
        '#title' => $this->t('Response status code'),
        '#markup' => $response->getStatusCode(),
      ];
      $build['response_header'] = [
        '#type' => 'details',
        '#title' => $this->t('Response header'),
        'data' => [
          '#markup' => htmlspecialchars(Yaml::encode($response->getHeaders())),
          '#prefix' => '<pre>',
          '#suffix' => '</pre>',
        ],
      ];
      $build['response_body'] = [
        '#type' => 'details',
        '#title' => $this->t('Response body'),
        'data' => [
          '#markup' => htmlspecialchars($response->getBody()),
          '#prefix' => '<pre>',
          '#suffix' => '</pre>',
        ],
      ];
    }

    drupal_set_message(\Drupal::service('renderer')->render($build), $type);
  }

}