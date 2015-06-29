<div class="wrap">
  <form action="options.php" method="post">
    <?php screen_icon(); ?>
    <h2><?php _e('Castle'); ?></h2>
<!--
    <p>
      <?php _e('This is where you enter you Castle credentials and make various settings'); ?>
    </p>

    <h3><?php _e('Generic Settings'); ?></h3>
//-->

    <table class="form-table">
      <tbody>
        <tr valign="top">
          <th scope="row">
            <label for="<?php self::_settings_id('appId'); ?>"><?php _e('App ID'); ?></label>
          </th>
          <td>
            <input type="text"
              class="regular-text code"
              id="<?php self::_settings_id('appId'); ?>"
              name="<?php self::_settings_name('appId'); ?>"
              value="<?php esc_attr_e($settings['appId']); ?>" />
          </td>
        </tr>

        <tr valign="top">
          <th scope="row">
            <label for="<?php self::_settings_id('apiSecret'); ?>"><?php _e('API Secret'); ?></label>
          </th>
          <td>
            <input type="text"
              class="regular-text code"
              id="<?php self::_settings_id('apiSecret'); ?>"
              name="<?php self::_settings_name('apiSecret'); ?>"
              value="<?php esc_attr_e($settings['apiSecret']); ?>" />
          </td>
        </tr>

        <tr valign="top">
          <th scope="row">
            <label>Castle active</label>
          </th>
          <td>
            <?php print($settings['activated'] == true ? 'yes' : 'no') ?>
          </td>
        </tr>
      </tbody>
    </table>

    <p class="submit">
      <?php settings_fields(self::SETTINGS_NAME); ?>
      <input type="submit" class="button button-primary" value="<?php _e('Save Changes'); ?>" />
    </p>
  </form>
</div>
