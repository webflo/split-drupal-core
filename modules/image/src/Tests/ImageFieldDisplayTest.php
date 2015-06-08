<?php

/**
 * @file
 * Definition of Drupal\image\Tests\ImageFieldDisplayTest.
 */

namespace Drupal\image\Tests;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\RoleInterface;
use Drupal\image\Entity\ImageStyle;

/**
 * Tests the display of image fields.
 *
 * @group image
 */
class ImageFieldDisplayTest extends ImageFieldTestBase {

  protected $dumpHeaders = TRUE;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('field_ui');

  /**
   * Test image formatters on node display for public files.
   */
  function testImageFieldFormattersPublic() {
    $this->_testImageFieldFormatters('public');
  }

  /**
   * Test image formatters on node display for private files.
   */
  function testImageFieldFormattersPrivate() {
    // Remove access content permission from anonymous users.
    user_role_change_permissions(RoleInterface::ANONYMOUS_ID, array('access content' => FALSE));
    $this->_testImageFieldFormatters('private');
  }

  /**
   * Test image formatters on node display.
   */
  function _testImageFieldFormatters($scheme) {
    $node_storage = $this->container->get('entity.manager')->getStorage('node');
    $field_name = strtolower($this->randomMachineName());
    $field_settings = array('alt_field_required' => 0);
    $instance = $this->createImageField($field_name, 'article', array('uri_scheme' => $scheme), $field_settings);

    // Go to manage display page.
    $this->drupalGet("admin/structure/types/manage/article/display");

    // Test for existence of link to image styles configuration.
    $this->drupalPostAjaxForm(NULL, array(), "{$field_name}_settings_edit");
    $this->assertLinkByHref(\Drupal::url('entity.image_style.collection'), 0, 'Link to image styles configuration is found');

    // Remove 'administer image styles' permission from testing admin user.
    $admin_user_roles = $this->adminUser->getRoles(TRUE);
    user_role_change_permissions(reset($admin_user_roles), array('administer image styles' => FALSE));

    // Go to manage display page again.
    $this->drupalGet("admin/structure/types/manage/article/display");

    // Test for absence of link to image styles configuration.
    $this->drupalPostAjaxForm(NULL, array(), "{$field_name}_settings_edit");
    $this->assertNoLinkByHref(\Drupal::url('entity.image_style.collection'), 'Link to image styles configuration is absent when permissions are insufficient');

    // Restore 'administer image styles' permission to testing admin user
    user_role_change_permissions(reset($admin_user_roles), array('administer image styles' => TRUE));

    // Create a new node with an image attached.
    $test_image = current($this->drupalGetTestFiles('image'));

    // Ensure that preview works.
    $this->previewNodeImage($test_image, $field_name, 'article');

    // After previewing, make the alt field required. It cannot be required
    // during preview because the form validation will fail.
    $instance->setSetting('alt_field_required', 1);
    $instance->save();

    // Create alt text for the image.
    $alt = $this->randomMachineName();

    // Save node.
    $nid = $this->uploadNodeImage($test_image, $field_name, 'article', $alt);
    $node_storage->resetCache(array($nid));
    $node = $node_storage->load($nid);

    // Test that the default formatter is being used.
    $file = $node->{$field_name}->entity;
    $image_uri = $file->getFileUri();
    $image = array(
      '#theme' => 'image',
      '#uri' => $image_uri,
      '#width' => 40,
      '#height' => 20,
      '#alt' => $alt,
    );
    $default_output = str_replace("\n", NULL, drupal_render($image));
    $this->assertRaw($default_output, 'Default formatter displaying correctly on full node view.');

    // Test the image linked to file formatter.
    $display_options = array(
      'type' => 'image',
      'settings' => array('image_link' => 'file'),
    );
    $display = entity_get_display('node', $node->getType(), 'default');
    $display->setComponent($field_name, $display_options)
      ->save();

    $image = array(
      '#theme' => 'image',
      '#uri' => $image_uri,
      '#width' => 40,
      '#height' => 20,
      '#alt' => $alt,
    );
    $default_output = '<a href="' . file_create_url($image_uri) . '">' . drupal_render($image) . '</a>';
    $this->drupalGet('node/' . $nid);
    $this->assertCacheTag($file->getCacheTags()[0]);
    $cache_tags_header = $this->drupalGetHeader('X-Drupal-Cache-Tags');
    $this->assertTrue(!preg_match('/ image_style\:/', $cache_tags_header), 'No image style cache tag found.');
    $this->assertRaw($default_output, 'Image linked to file formatter displaying correctly on full node view.');
    // Verify that the image can be downloaded.
    $this->assertEqual(file_get_contents($test_image->uri), $this->drupalGet(file_create_url($image_uri)), 'File was downloaded successfully.');
    if ($scheme == 'private') {
      // Only verify HTTP headers when using private scheme and the headers are
      // sent by Drupal.
      $this->assertEqual($this->drupalGetHeader('Content-Type'), 'image/png', 'Content-Type header was sent.');
      $this->assertTrue(strstr($this->drupalGetHeader('Cache-Control'),'private') !== FALSE, 'Cache-Control header was sent.');

      // Log out and try to access the file.
      $this->drupalLogout();
      $this->drupalGet(file_create_url($image_uri));
      $this->assertResponse('403', 'Access denied to original image as anonymous user.');

      // Log in again.
      $this->drupalLogin($this->adminUser);
    }

    // Test the image linked to content formatter.
    $display_options['settings']['image_link'] = 'content';
    $display->setComponent($field_name, $display_options)
      ->save();
    $image = array(
      '#theme' => 'image',
      '#uri' => $image_uri,
      '#width' => 40,
      '#height' => 20,
    );
    $this->drupalGet('node/' . $nid);
    $this->assertCacheTag($file->getCacheTags()[0]);
    $cache_tags_header = $this->drupalGetHeader('X-Drupal-Cache-Tags');
    $this->assertTrue(!preg_match('/ image_style\:/', $cache_tags_header), 'No image style cache tag found.');
    $elements = $this->xpath(
      '//a[@href=:path]/img[@src=:url and @alt=:alt and @width=:width and @height=:height]',
      array(
        ':path' => $node->url(),
        ':url' => file_create_url($image['#uri']),
        ':width' => $image['#width'],
        ':height' => $image['#height'],
        ':alt' => $alt,
      )
    );
    $this->assertEqual(count($elements), 1, 'Image linked to content formatter displaying correctly on full node view.');

    // Test the image style 'thumbnail' formatter.
    $display_options['settings']['image_link'] = '';
    $display_options['settings']['image_style'] = 'thumbnail';
    $display->setComponent($field_name, $display_options)
      ->save();

    // Ensure the derivative image is generated so we do not have to deal with
    // image style callback paths.
    $this->drupalGet(ImageStyle::load('thumbnail')->buildUrl($image_uri));
    $image_style = array(
      '#theme' => 'image_style',
      '#uri' => $image_uri,
      '#width' => 40,
      '#height' => 20,
      '#style_name' => 'thumbnail',
      '#alt' => $alt,
    );
    $default_output = drupal_render($image_style);
    $this->drupalGet('node/' . $nid);
    $image_style = ImageStyle::load('thumbnail');
    $this->assertCacheTag($image_style->getCacheTags()[0]);
    $this->assertRaw($default_output, 'Image style thumbnail formatter displaying correctly on full node view.');

    if ($scheme == 'private') {
      // Log out and try to access the file.
      $this->drupalLogout();
      $this->drupalGet(ImageStyle::load('thumbnail')->buildUrl($image_uri));
      $this->assertResponse('403', 'Access denied to image style thumbnail as anonymous user.');
    }
  }

  /**
   * Tests for image field settings.
   */
  function testImageFieldSettings() {
    $node_storage = $this->container->get('entity.manager')->getStorage('node');
    $test_image = current($this->drupalGetTestFiles('image'));
    list(, $test_image_extension) = explode('.', $test_image->filename);
    $field_name = strtolower($this->randomMachineName());
    $field_settings = array(
      'alt_field' => 1,
      'file_extensions' => $test_image_extension,
      'max_filesize' => '50 KB',
      'max_resolution' => '100x100',
      'min_resolution' => '10x10',
      'title_field' => 1,
    );
    $widget_settings = array(
      'preview_image_style' => 'medium',
    );
    $field = $this->createImageField($field_name, 'article', array(), $field_settings, $widget_settings);

    // Verify that the min/max resolution set on the field are properly
    // extracted, and displayed, on the image field's configuration form.
    $this->drupalGet('admin/structure/types/manage/article/fields/' . $field->id());
    $this->assertFieldByName('settings[max_resolution][x]', '100', 'Expected max resolution X value of 100.');
    $this->assertFieldByName('settings[max_resolution][y]', '100', 'Expected max resolution Y value of 100.');
    $this->assertFieldByName('settings[min_resolution][x]', '10', 'Expected min resolution X value of 10.');
    $this->assertFieldByName('settings[min_resolution][y]', '10', 'Expected min resolution Y value of 10.');

    $this->drupalGet('node/add/article');
    $this->assertText(t('50 KB limit.'), 'Image widget max file size is displayed on article form.');
    $this->assertText(t('Allowed types: @extensions.', array('@extensions' => $test_image_extension)), 'Image widget allowed file types displayed on article form.');
    $this->assertText(t('Images must be larger than 10x10 pixels. Images larger than 100x100 pixels will be resized.'), 'Image widget allowed resolution displayed on article form.');

    // We have to create the article first and then edit it because the alt
    // and title fields do not display until the image has been attached.

    // Create alt text for the image.
    $alt = $this->randomMachineName();

    $nid = $this->uploadNodeImage($test_image, $field_name, 'article', $alt);
    $this->drupalGet('node/' . $nid . '/edit');
    $this->assertFieldByName($field_name . '[0][alt]', '', 'Alt field displayed on article form.');
    $this->assertFieldByName($field_name . '[0][title]', '', 'Title field displayed on article form.');
    // Verify that the attached image is being previewed using the 'medium'
    // style.
    $node_storage->resetCache(array($nid));
    $node = $node_storage->load($nid);
    $file = $node->{$field_name}->entity;
    $image_style = array(
      '#theme' => 'image_style',
      '#uri' => $file->getFileUri(),
      '#width' => 40,
      '#height' => 20,
      '#style_name' => 'medium',
    );
    $default_output = drupal_render($image_style);
    $this->assertRaw($default_output, "Preview image is displayed using 'medium' style.");

    // Add alt/title fields to the image and verify that they are displayed.
    $image = array(
      '#theme' => 'image',
      '#uri' => $file->getFileUri(),
      '#alt' => $alt,
      '#title' => $this->randomMachineName(),
      '#width' => 40,
      '#height' => 20,
    );
    $edit = array(
      $field_name . '[0][alt]' => $image['#alt'],
      $field_name . '[0][title]' => $image['#title'],
    );
    $this->drupalPostForm('node/' . $nid . '/edit', $edit, t('Save and keep published'));
    $default_output = str_replace("\n", NULL, drupal_render($image));
    $this->assertRaw($default_output, 'Image displayed using user supplied alt and title attributes.');

    // Verify that alt/title longer than allowed results in a validation error.
    $test_size = 2000;
    $edit = array(
      $field_name . '[0][alt]' => $this->randomMachineName($test_size),
      $field_name . '[0][title]' => $this->randomMachineName($test_size),
    );
    $this->drupalPostForm('node/' . $nid . '/edit', $edit, t('Save and keep published'));
    $schema = $field->getFieldStorageDefinition()->getSchema();
    $this->assertRaw(t('Alternative text cannot be longer than %max characters but is currently %length characters long.', array(
      '%max' => $schema['columns']['alt']['length'],
      '%length' => $test_size,
    )));
    $this->assertRaw(t('Title cannot be longer than %max characters but is currently %length characters long.', array(
      '%max' => $schema['columns']['title']['length'],
      '%length' => $test_size,
    )));

    // Set cardinality to unlimited and add upload a second image.
    // The image widget is extending on the file widget, but the image field
    // type does not have the 'display_field' setting which is expected by
    // the file widget. This resulted in notices before when cardinality is not
    // 1, so we need to make sure the file widget prevents these notices by
    // providing all settings, even if they are not used.
    // @see FileWidget::formMultipleElements().
    $this->drupalPostForm('admin/structure/types/manage/article/fields/node.article.' . $field_name . '/storage', array('cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED), t('Save field settings'));
    $edit = array(
      'files[' . $field_name . '_1][]' => drupal_realpath($test_image->uri),
    );
    $this->drupalPostForm('node/' . $node->id() . '/edit', $edit, t('Save and keep published'));
    // Add the required alt text.
    $this->drupalPostForm(NULL, [$field_name . '[1][alt]' => $alt], t('Save and keep published'));
    $this->assertText(format_string('Article @title has been updated.', array('@title' => $node->getTitle())));

    // Assert ImageWidget::process() calls FieldWidget::process().
    $this->drupalGet('node/' . $node->id() . '/edit');
    $edit = array(
      'files[' . $field_name . '_2][]' => drupal_realpath($test_image->uri),
    );
    $this->drupalPostAjaxForm(NULL, $edit, $field_name . '_2_upload_button');
    $this->assertNoRaw('<input multiple type="file" id="edit-' . strtr($field_name, '_', '-') . '-2-upload" name="files[' . $field_name . '_2][]" size="22" class="js-form-file form-file">');
    $this->assertRaw('<input multiple type="file" id="edit-' . strtr($field_name, '_', '-') . '-3-upload" name="files[' . $field_name . '_3][]" size="22" class="js-form-file form-file">');
  }

  /**
   * Test use of a default image with an image field.
   */
  function testImageFieldDefaultImage() {
    $node_storage = $this->container->get('entity.manager')->getStorage('node');
    // Create a new image field.
    $field_name = strtolower($this->randomMachineName());
    $this->createImageField($field_name, 'article');

    // Create a new node, with no images and verify that no images are
    // displayed.
    $node = $this->drupalCreateNode(array('type' => 'article'));
    $this->drupalGet('node/' . $node->id());
    // Verify that no image is displayed on the page by checking for the class
    // that would be used on the image field.
    $this->assertNoPattern('<div class="(.*?)field-name-' . strtr($field_name, '_', '-') . '(.*?)">', 'No image displayed when no image is attached and no default image specified.');
    $cache_tags_header = $this->drupalGetHeader('X-Drupal-Cache-Tags');
    $this->assertTrue(!preg_match('/ image_style\:/', $cache_tags_header), 'No image style cache tag found.');

    // Add a default image to the public image field.
    $images = $this->drupalGetTestFiles('image');
    $alt = $this->randomString(512);
    $title = $this->randomString(1024);
    $edit = array(
      'files[settings_default_image_uuid]' => drupal_realpath($images[0]->uri),
      'settings[default_image][alt]' => $alt,
      'settings[default_image][title]' => $title,
    );
    $this->drupalPostForm("admin/structure/types/manage/article/fields/node.article.$field_name/storage", $edit, t('Save field settings'));
    // Clear field definition cache so the new default image is detected.
    \Drupal::entityManager()->clearCachedFieldDefinitions();
    $field_storage = FieldStorageConfig::loadByName('node', $field_name);
    $default_image = $field_storage->getSetting('default_image');
    $file = \Drupal::entityManager()->loadEntityByUuid('file', $default_image['uuid']);
    $this->assertTrue($file->isPermanent(), 'The default image status is permanent.');
    $image = array(
      '#theme' => 'image',
      '#uri' => $file->getFileUri(),
      '#alt' => $alt,
      '#title' => $title,
      '#width' => 40,
      '#height' => 20,
    );
    $default_output = str_replace("\n", NULL, drupal_render($image));
    $this->drupalGet('node/' . $node->id());
    $this->assertCacheTag($file->getCacheTags()[0]);
    $cache_tags_header = $this->drupalGetHeader('X-Drupal-Cache-Tags');
    $this->assertTrue(!preg_match('/ image_style\:/', $cache_tags_header), 'No image style cache tag found.');
    $this->assertRaw($default_output, 'Default image displayed when no user supplied image is present.');

    // Create a node with an image attached and ensure that the default image
    // is not displayed.

    // Create alt text for the image.
    $alt = $this->randomMachineName();

    $nid = $this->uploadNodeImage($images[1], $field_name, 'article', $alt);
    $node_storage->resetCache(array($nid));
    $node = $node_storage->load($nid);
    $file = $node->{$field_name}->entity;
    $image = array(
      '#theme' => 'image',
      '#uri' => $file->getFileUri(),
      '#width' => 40,
      '#height' => 20,
      '#alt' => $alt,
    );
    $image_output = str_replace("\n", NULL, drupal_render($image));
    $this->drupalGet('node/' . $nid);
    $this->assertCacheTag($file->getCacheTags()[0]);
    $cache_tags_header = $this->drupalGetHeader('X-Drupal-Cache-Tags');
    $this->assertTrue(!preg_match('/ image_style\:/', $cache_tags_header), 'No image style cache tag found.');
    $this->assertNoRaw($default_output, 'Default image is not displayed when user supplied image is present.');
    $this->assertRaw($image_output, 'User supplied image is displayed.');

    // Remove default image from the field and make sure it is no longer used.
    $edit = array(
      'settings[default_image][uuid][fids]' => 0,
    );
    $this->drupalPostForm("admin/structure/types/manage/article/fields/node.article.$field_name/storage", $edit, t('Save field settings'));
    // Clear field definition cache so the new default image is detected.
    \Drupal::entityManager()->clearCachedFieldDefinitions();
    $field_storage = FieldStorageConfig::loadByName('node', $field_name);
    $default_image = $field_storage->getSetting('default_image');
    $this->assertFalse($default_image['uuid'], 'Default image removed from field.');
    // Create an image field that uses the private:// scheme and test that the
    // default image works as expected.
    $private_field_name = strtolower($this->randomMachineName());
    $this->createImageField($private_field_name, 'article', array('uri_scheme' => 'private'));
    // Add a default image to the new field.
    $edit = array(
      'files[settings_default_image_uuid]' => drupal_realpath($images[1]->uri),
      'settings[default_image][alt]' => $alt,
      'settings[default_image][title]' => $title,
    );
    $this->drupalPostForm('admin/structure/types/manage/article/fields/node.article.' . $private_field_name . '/storage', $edit, t('Save field settings'));
    // Clear field definition cache so the new default image is detected.
    \Drupal::entityManager()->clearCachedFieldDefinitions();

    $private_field_storage = FieldStorageConfig::loadByName('node', $private_field_name);
    $default_image = $private_field_storage->getSetting('default_image');
    $file = \Drupal::entityManager()->loadEntityByUuid('file', $default_image['uuid']);
    $this->assertEqual('private', file_uri_scheme($file->getFileUri()), 'Default image uses private:// scheme.');
    $this->assertTrue($file->isPermanent(), 'The default image status is permanent.');
    // Create a new node with no image attached and ensure that default private
    // image is displayed.
    $node = $this->drupalCreateNode(array('type' => 'article'));
    $image = array(
      '#theme' => 'image',
      '#uri' => $file->getFileUri(),
      '#alt' => $alt,
      '#title' => $title,
      '#width' => 40,
      '#height' => 20,
    );
    $default_output = str_replace("\n", NULL, drupal_render($image));
    $this->drupalGet('node/' . $node->id());
    $this->assertCacheTag($file->getCacheTags()[0]);
    $cache_tags_header = $this->drupalGetHeader('X-Drupal-Cache-Tags');
    $this->assertTrue(!preg_match('/ image_style\:/', $cache_tags_header), 'No image style cache tag found.');
    $this->assertRaw($default_output, 'Default private image displayed when no user supplied image is present.');
  }

}
