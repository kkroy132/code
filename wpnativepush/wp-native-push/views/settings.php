<?php defined( 'ABSPATH' ) || exit; ?>

<div class="wnp-settings-grid">

    <!-- ── Left column: General settings form ── -->
    <div class="wnp-settings-col">

        <!-- VAPID Keys card -->
        <div class="wnp-card">
            <div class="wnp-card__header">
                <h2 class="wnp-card__title">🔑 <?php esc_html_e( 'VAPID Keys', 'wp-native-push' ); ?></h2>
            </div>
            <div class="wnp-card__body">
                <table class="form-table" role="presentation" style="margin:0">
                    <tr>
                        <th scope="row" style="width:130px"><?php esc_html_e( 'Public Key', 'wp-native-push' ); ?></th>
                        <td>
                            <div class="wnp-key-wrap">
                                <code class="wnp-key-box" id="wnp-pubkey"
                                      title="<?php esc_attr_e( 'Click to copy', 'wp-native-push' ); ?>">
                                    <?php echo esc_html( $public_key ); ?>
                                </code>
                                <button type="button" class="button button-small" id="wnp-copy-key">
                                    📋 <?php esc_html_e( 'Copy', 'wp-native-push' ); ?>
                                </button>
                            </div>
                            <p class="description"><?php esc_html_e( 'This is safe to expose — it is sent to browsers.', 'wp-native-push' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Private Key', 'wp-native-push' ); ?></th>
                        <td>
                            <span class="wnp-private-key-msg">
                                🔒 <?php esc_html_e( 'Stored securely — never displayed.', 'wp-native-push' ); ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
            <div class="wnp-card__footer">
                <form method="post"
                      action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                      onsubmit="return confirm('<?php esc_attr_e( 'This invalidates ALL existing subscriptions. All subscribers must re-subscribe. Continue?', 'wp-native-push' ); ?>');">
                    <?php wp_nonce_field( 'wnp_regenerate_keys' ); ?>
                    <input type="hidden" name="action" value="wnp_regenerate_keys" />
                    <button type="submit" class="button button-secondary">
                        🔄 <?php esc_html_e( 'Regenerate VAPID Keys', 'wp-native-push' ); ?>
                    </button>
                    <span class="wnp-danger-note">
                        ⚠️ <?php esc_html_e( 'All current subscribers will need to re-subscribe.', 'wp-native-push' ); ?>
                    </span>
                </form>
            </div>
        </div><!-- .wnp-card -->

        <!-- General settings form -->
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'wnp_save_settings' ); ?>
            <input type="hidden" name="action" value="wnp_save_settings" />

            <!-- VAPID Identity card -->
            <div class="wnp-card" style="margin-top:20px">
                <div class="wnp-card__header">
                    <h2 class="wnp-card__title">🌐 <?php esc_html_e( 'VAPID Identity', 'wp-native-push' ); ?></h2>
                </div>
                <div class="wnp-card__body">
                    <table class="form-table" role="presentation" style="margin:0">
                        <tr>
                            <th scope="row" style="width:130px">
                                <label for="vapid_subject"><?php esc_html_e( 'Contact Email', 'wp-native-push' ); ?></label>
                            </th>
                            <td>
                                <input type="email" id="vapid_subject" name="vapid_subject"
                                       class="regular-text"
                                       value="<?php echo esc_attr( $subject ); ?>"
                                       placeholder="mailto:admin@example.com" />
                                <p class="description">
                                    <?php esc_html_e( 'Used in the VAPID JWT sub claim. Required by the Web Push protocol.', 'wp-native-push' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Popup settings card -->
            <div class="wnp-card" style="margin-top:20px">
                <div class="wnp-card__header">
                    <h2 class="wnp-card__title">💬 <?php esc_html_e( 'Subscription Popup', 'wp-native-push' ); ?></h2>
                </div>
                <div class="wnp-card__body">
                    <table class="form-table" role="presentation" style="margin:0">
                        <tr>
                            <th scope="row" style="width:130px">
                                <label for="popup_title"><?php esc_html_e( 'Popup Title', 'wp-native-push' ); ?></label>
                            </th>
                            <td>
                                <input type="text" id="popup_title" name="popup_title"
                                       class="regular-text"
                                       value="<?php echo esc_attr( $popup_title ); ?>"
                                       maxlength="80" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="popup_body"><?php esc_html_e( 'Description', 'wp-native-push' ); ?></label>
                            </th>
                            <td>
                                <textarea id="popup_body" name="popup_body"
                                          class="large-text" rows="2"><?php echo esc_textarea( $popup_body ); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="popup_delay"><?php esc_html_e( 'Delay (seconds)', 'wp-native-push' ); ?></label>
                            </th>
                            <td>
                                <input type="number" id="popup_delay" name="popup_delay"
                                       class="small-text"
                                       value="<?php echo esc_attr( $popup_delay ); ?>"
                                       min="0" max="120" />
                                <p class="description">
                                    <?php esc_html_e( 'Seconds after page load before showing popup to new visitors. 0 = immediately.', 'wp-native-push' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div style="margin-top:16px">
                <?php submit_button( __( 'Save Settings', 'wp-native-push' ), 'primary', 'submit', false ); ?>
            </div>
        </form>

    </div><!-- .wnp-settings-col -->

    <!-- ── Right column: Popup preview ── -->
    <div class="wnp-settings-preview-col">
        <h3 style="margin-top:0"><?php esc_html_e( 'Popup Preview', 'wp-native-push' ); ?></h3>
        <div class="wnp-popup-preview">
            <div class="wnp-popup-preview__inner">
                <div class="wnp-popup-preview__icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                </div>
                <div class="wnp-popup-preview__text">
                    <p class="wnp-popup-preview__title" id="preview-popup-title">
                        <?php echo esc_html( $popup_title ); ?>
                    </p>
                    <p class="wnp-popup-preview__body" id="preview-popup-body">
                        <?php echo esc_html( $popup_body ); ?>
                    </p>
                </div>
            </div>
            <div class="wnp-popup-preview__btns">
                <span class="wnp-popup-preview__btn wnp-popup-preview__btn--allow">
                    <?php esc_html_e( 'Allow Notifications', 'wp-native-push' ); ?>
                </span>
                <span class="wnp-popup-preview__btn wnp-popup-preview__btn--deny">
                    <?php esc_html_e( 'No thanks', 'wp-native-push' ); ?>
                </span>
            </div>
        </div>
        <p class="description" style="margin-top:10px;font-size:11px">
            <?php esc_html_e( 'Live preview updates as you type.', 'wp-native-push' ); ?>
        </p>

        <!-- System info -->
        <div class="wnp-card" style="margin-top:24px">
            <div class="wnp-card__header">
                <h3 class="wnp-card__title" style="font-size:13px">
                    🖥 <?php esc_html_e( 'System Info', 'wp-native-push' ); ?>
                </h3>
            </div>
            <div class="wnp-card__body">
                <table style="width:100%;font-size:12px;border-collapse:collapse">
                    <?php
                    $checks = [
                        __( 'PHP Version', 'wp-native-push' )    => [ PHP_VERSION, version_compare( PHP_VERSION, '7.3', '>=' ) ],
                        __( 'OpenSSL', 'wp-native-push' )        => [ extension_loaded( 'openssl' ) ? 'Loaded' : 'Missing', extension_loaded( 'openssl' ) ],
                        __( 'EC Key Support', 'wp-native-push' ) => [ defined( 'OPENSSL_KEYTYPE_EC' ) ? 'Available' : 'Missing', defined( 'OPENSSL_KEYTYPE_EC' ) ],
                        __( 'hash_hkdf()', 'wp-native-push' )    => [ function_exists( 'hash_hkdf' ) ? 'Available' : 'Missing', function_exists( 'hash_hkdf' ) ],
                        __( 'HTTPS', 'wp-native-push' )          => [ is_ssl() ? 'Yes' : 'No (required!)', is_ssl() ],
                        __( 'VAPID Keys', 'wp-native-push' )     => [ get_option( 'wnp_vapid_public_key' ) ? 'Generated' : 'Missing', (bool) get_option( 'wnp_vapid_public_key' ) ],
                    ];
                    foreach ( $checks as $label => [ $value, $ok ] ) : ?>
                    <tr style="border-bottom:1px solid #f0f0f1">
                        <td style="padding:5px 0;color:#646970"><?php echo esc_html( $label ); ?></td>
                        <td style="padding:5px 0;text-align:right">
                            <span style="color:<?php echo $ok ? '#00a32a' : '#d63638'; ?>;font-weight:600">
                                <?php echo $ok ? '✓' : '✗'; ?>
                            </span>
                            <?php echo esc_html( $value ); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>

    </div><!-- .wnp-settings-preview-col -->

</div><!-- .wnp-settings-grid -->
