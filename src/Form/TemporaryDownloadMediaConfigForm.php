<?php

namespace Drupal\temporary_downloadable_media\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Mirador Settings Form.
 */
class TemporaryDownloadMediaConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'islandora_lite.temporary_downloadable_media.form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $config = $this->config('temporary_downloadable_media.settings');

    $form['temporary_downloadable_media_fieldset'] = [
      '#type' => 'fieldset',
    ];

    // Call the helper function to get the options list.
    $user_options = get_user_select_options();

    // Add a placeholder/empty option at the beginning.
    $user_options = ['' => $this->t('- Select a User -')] + $user_options;

    // 1. Create the select list element.
    $form['temporary_downloadable_media_fieldset']['user_select'] = [
      '#type' => 'select',
      '#title' => $this->t('Select User to grant temporary access: '),
      '#options' => $user_options,
      '#required' => TRUE,
      '#description' => $this->t('A list of all active users on the site.'),
      '#default_value' => ($config->get("user_select") !== NULL) ? $config->get("user_select") : "",
    ];

    $form['temporary_downloadable_media_fieldset']['token_expired_duration'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Enter number of hours before the temporary access is expired:'),
      '#description' => $this->t('In hours(s) determine how long the temporary access is valid, default is 24 hours (1 day)'),
      '#default_value' => ($config->get("token_expired_duration") !== NULL) ? $config->get("token_expired_duration") : 24,
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('temporary_downloadable_media.settings');
    $config->set('user_select', $form_state->getValue('user_select'));
    $config->set('token_expired_duration', $form_state->getValue('token_expired_duration'));
    $config->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'temporary_downloadable_media.settings',
    ];
  }

}
