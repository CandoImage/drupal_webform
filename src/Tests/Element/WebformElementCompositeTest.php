<?php

namespace Drupal\webform\Tests\Element;

use Drupal\webform\Entity\Webform;

/**
 * Tests for composite element (builder).
 *
 * @group Webform
 */
class WebformElementCompositeTest extends WebformElementTestBase {

  /**
   * Webforms to load.
   *
   * @var array
   */
  protected static $testWebforms = [
    'test_element_composite',
    'test_element_composite_wrapper',
  ];

  /**
   * Test composite (builder).
   */
  public function testComposite() {

    /**************************************************************************/
    // Builder.
    /**************************************************************************/

    $webform = Webform::load('test_element_composite');

    // Check processing for user who can't edit source.
    $this->postSubmission($webform);
    $this->assertRaw("webform_element_composite_basic:
  first_name:
    '#type': textfield
    '#required': true
    '#title': 'First name'
  last_name:
    '#type': textfield
    '#required': true
    '#title': 'Last name'
webform_element_composite_advanced:
  first_name:
    '#type': textfield
    '#title': 'First name'
  last_name:
    '#type': textfield
    '#title': 'Last name'
  gender:
    '#type': select
    '#options':
      Male: Male
      Female: Female
    '#title': Gender
  martial_status:
    '#type': webform_select_other
    '#options': marital_status
    '#title': 'Martial status'
  employment_status:
    '#type': webform_select_other
    '#options': employment_status
    '#title': 'Employment status'
  age:
    '#type': number
    '#title': Age
    '#field_suffix': ' yrs. old'
    '#min': 1
    '#max': 125");

    // Check processing for user who can edit source.
    $this->drupalLogin($this->rootUser);
    $this->postSubmission($webform);
    $this->assertRaw("webform_element_composite_basic:
  first_name:
    '#type': textfield
    '#required': true
    '#title': 'First name'
  last_name:
    '#type': textfield
    '#required': true
    '#title': 'Last name'
webform_element_composite_advanced:
  first_name:
    '#type': textfield
    '#title': 'First name'
  last_name:
    '#type': textfield
    '#title': 'Last name'
  gender:
    '#type': select
    '#options':
      Male: Male
      Female: Female
    '#title': Gender
  martial_status:
    '#type': webform_select_other
    '#options': marital_status
    '#title': 'Martial status'
  employment_status:
    '#type': webform_select_other
    '#options': employment_status
    '#title': 'Employment status'
  age:
    '#type': number
    '#title': Age
    '#field_suffix': ' yrs. old'
    '#min': 1
    '#max': 125");

    /**************************************************************************/
    // Wrapper.
    /**************************************************************************/

    $this->drupalGet('webform/test_element_composite_wrapper');

    // Check fieldset wrapper.
    $this->assertRaw('<fieldset data-drupal-selector="edit-radios-wrapper-fieldset" id="edit-radios-wrapper-fieldset--wrapper" class="radios--wrapper fieldgroup form-composite webform-composite-visible-title required js-webform-type-radios webform-type-radios js-form-item form-item js-form-wrapper form-wrapper">');

    // Check fieldset wrapper with hidden title.
    $this->assertRaw('<fieldset data-drupal-selector="edit-radios-wrapper-fieldset-hidden-title" id="edit-radios-wrapper-fieldset-hidden-title--wrapper" class="radios--wrapper fieldgroup form-composite webform-composite-hidden-title required js-webform-type-radios webform-type-radios js-form-item form-item js-form-wrapper form-wrapper">');

    // Check form element wrapper.
    $this->assertRaw('<div class="js-form-item form-item js-form-type-radios form-type-radios js-form-item-radios-wrapper-form-element form-item-radios-wrapper-form-element">');

    // Check container wrapper.
    $this->assertRaw('<div data-drupal-selector="edit-radios-wrapper-container" id="edit-radios-wrapper-container--wrapper" class="radios--wrapper fieldgroup form-composite js-form-wrapper form-wrapper">');

  }

}
