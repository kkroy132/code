<?php
defined( 'ABSPATH' ) || exit;

$total_subs    = WNP_Subscriber::count();
$recent_jobs   = array_slice( array_reverse( WNP_Sender::get_queue() ), 0, 20 );
$categories    = get_categories( [ 'hide_empty' => false ] );
$tags          = get_tags( [ 'hide_empty' => false ] );
$site_host     = wp_parse_url( home_url(), PHP_URL_HOST );

$desktop_count = 0; $mobile_count = 0;
foreach ( WNP_Subscriber::get_all( 9999, 0 ) as $s ) {
    $ua = strtolower( $s['user_agent'] ?? '' );
    if ( str_contains( $ua, 'mobile' ) || str_contains( $ua, 'android' ) ) $mobile_count++;
    else $desktop_count++;
}
?>

<?php if ( $total_subs === 0 ) : ?>
<div class="notice notice-warning inline" style="margin:0 0 20px">
    <p><?php esc_html_e( 'No subscribers yet. Visit your site frontend to subscribe via the popup.', 'wp-native-push' ); ?></p>
</div>
<?php endif; ?>

<div id="wnp-compose-notice" class="wnp-cn" style="display:none"></div>

<!-- ══════════ MAIN LAYOUT ══════════ -->
<div class="wnp-cl">

<!-- ░░ LEFT — Form cards ░░ -->
<div class="wnp-cl__main">

<!-- ▌Card: Notification Content ▐ -->
<div class="wnp-c">
    <div class="wnp-c__h wnp-c__h--open" data-c="content">
        <span>&#x1F4DD;</span> <?php esc_html_e( 'Notification Content', 'wp-native-push' ); ?>
        <span class="wnp-c__arr">&#9660;</span>
    </div>
    <div class="wnp-c__b" id="wnp-c-content">

        <div class="wnp-row">
            <div class="wnp-row__hd">
                <label for="wnp-title" class="wnp-lbl"><?php esc_html_e( 'Title', 'wp-native-push' ); ?> <span class="wnp-req">*</span></label>
                <span class="wnp-cnt" id="wnp-title-cnt">0 / 60</span>
            </div>
            <div style="position:relative">
                <input type="text" id="wnp-title" maxlength="60" class="wnp-inp"
                    placeholder="e.g. New Post: Top 10 Tips for Better Sleep" />
                <button type="button" class="wnp-emj" data-for="wnp-title">&#x1F642;</button>
            </div>
        </div>

        <div class="wnp-row">
            <div class="wnp-row__hd">
                <label for="wnp-body" class="wnp-lbl"><?php esc_html_e( 'Message', 'wp-native-push' ); ?> <span class="wnp-req">*</span></label>
                <span class="wnp-cnt" id="wnp-body-cnt">0 / 120</span>
            </div>
            <div style="position:relative">
                <textarea id="wnp-body" maxlength="120" rows="3" class="wnp-inp"
                    placeholder="Brief, compelling message — under 100 characters is best."></textarea>
                <button type="button" class="wnp-emj wnp-emj--ta" data-for="wnp-body">&#x1F642;</button>
            </div>
        </div>

        <div class="wnp-row">
            <label for="wnp-url" class="wnp-lbl"><?php esc_html_e( 'Destination URL', 'wp-native-push' ); ?></label>
            <input type="url" id="wnp-url" class="wnp-inp" value="<?php echo esc_url( home_url() ); ?>" />
        </div>

        <!-- Media -->
        <div class="wnp-media-grid">
            <?php
            $media_items = [
                'icon'  => [ 'label' => 'Icon',       'hint' => '192×192 px', 'ph' => '&#x1F5BC;' ],
                'badge' => [ 'label' => 'Badge',      'hint' => '72×72 px',   'ph' => '&#x1F514;' ],
                'image' => [ 'label' => 'Hero Image', 'hint' => '1440×720 px','ph' => '&#x1F304;' ],
            ];
            foreach ( $media_items as $key => $item ) :
            ?>
            <div class="wnp-mp">
                <label class="wnp-lbl"><?php echo esc_html( $item['label'] ); ?></label>
                <div class="wnp-mp__box" id="wnp-mp-<?php echo esc_attr( $key ); ?>">
                    <div class="wnp-mp__thumb" id="wnp-thumb-<?php echo esc_attr( $key ); ?>">
                        <span><?php echo $item['ph']; // phpcs:ignore ?></span>
                    </div>
                    <div class="wnp-mp__btns">
                        <button type="button" class="button button-small wnp-mc" data-f="<?php echo esc_attr( $key ); ?>">
                            <?php esc_html_e( 'Choose', 'wp-native-push' ); ?>
                        </button>
                        <button type="button" class="button button-small wnp-mr" data-f="<?php echo esc_attr( $key ); ?>" style="display:none;color:#d63638">
                            <?php esc_html_e( 'Remove', 'wp-native-push' ); ?>
                        </button>
                    </div>
                </div>
                <input type="hidden" id="wnp-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" />
                <p class="wnp-hint"><?php echo esc_html( $item['hint'] ); ?></p>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
</div>

<!-- ▌Card: Audience ▐ -->
<div class="wnp-c">
    <div class="wnp-c__h" data-c="audience">
        <span>&#x1F3AF;</span> <?php esc_html_e( 'Audience', 'wp-native-push' ); ?>
        <span class="wnp-reach-pill" id="wnp-reach-pill"><?php echo esc_html( $total_subs ); ?> subscribers</span>
        <span class="wnp-c__arr">&#9660;</span>
    </div>
    <div class="wnp-c__b" id="wnp-c-audience" style="display:none">
        <div class="wnp-aud-grid">
            <?php
            $aud_opts = [
                'all'      => [ 'icon' => '&#x1F465;', 'label' => 'All Subscribers',  'cnt' => $total_subs ],
                'desktop'  => [ 'icon' => '&#x1F5A5;', 'label' => 'Desktop Only',     'cnt' => $desktop_count ],
                'mobile'   => [ 'icon' => '&#x1F4F1;', 'label' => 'Mobile Only',      'cnt' => $mobile_count ],
                'category' => [ 'icon' => '&#x1F4C2;', 'label' => 'By Category',      'cnt' => null ],
                'tag'      => [ 'icon' => '&#x1F3F7;', 'label' => 'By Tag',           'cnt' => null ],
                'custom'   => [ 'icon' => '&#x2699;',  'label' => 'Custom Segment',   'cnt' => null ],
            ];
            foreach ( $aud_opts as $v => $o ) :
            ?>
            <label class="wnp-ao <?php echo $v === 'all' ? 'wnp-ao--on' : ''; ?>">
                <input type="radio" name="audience" value="<?php echo esc_attr( $v ); ?>" <?php checked( $v, 'all' ); ?> style="display:none" />
                <span class="wnp-ao__i"><?php echo $o['icon']; // phpcs:ignore ?></span>
                <span class="wnp-ao__l"><?php echo esc_html( $o['label'] ); ?></span>
                <?php if ( $o['cnt'] !== null ) : ?><span class="wnp-ao__c"><?php echo esc_html( $o['cnt'] ); ?></span><?php endif; ?>
            </label>
            <?php endforeach; ?>
        </div>

        <div id="wnp-cat-wrap" style="display:none;margin-top:14px">
            <label class="wnp-lbl"><?php esc_html_e( 'Categories', 'wp-native-push' ); ?></label>
            <select multiple class="wnp-inp" style="height:100px">
                <?php foreach ( $categories as $c ) : ?>
                <option value="<?php echo esc_attr( $c->term_id ); ?>"><?php echo esc_html( $c->name ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="wnp-tag-wrap" style="display:none;margin-top:14px">
            <label class="wnp-lbl"><?php esc_html_e( 'Tags', 'wp-native-push' ); ?></label>
            <select multiple class="wnp-inp" style="height:100px">
                <?php foreach ( $tags as $t ) : ?>
                <option value="<?php echo esc_attr( $t->term_id ); ?>"><?php echo esc_html( $t->name ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Reach bar -->
        <div class="wnp-rb">
            <span class="wnp-rb__l"><?php esc_html_e( 'Estimated Reach', 'wp-native-push' ); ?></span>
            <div class="wnp-rb__t"><div class="wnp-rb__f" id="wnp-rb-fill"></div></div>
            <span class="wnp-rb__n" id="wnp-rb-num"><?php echo esc_html( $total_subs ); ?></span>
        </div>
    </div>
</div>

<!-- ▌Card: Action Buttons ▐ -->
<div class="wnp-c">
    <div class="wnp-c__h" data-c="actions">
        <span>&#x1F518;</span> <?php esc_html_e( 'Action Buttons', 'wp-native-push' ); ?>
        <span class="wnp-c__sub"><?php esc_html_e( 'Optional — up to 2', 'wp-native-push' ); ?></span>
        <span class="wnp-c__arr">&#9660;</span>
    </div>
    <div class="wnp-c__b" id="wnp-c-actions" style="display:none">
        <?php for ( $i = 1; $i <= 2; $i++ ) : ?>
        <div class="wnp-bp">
            <div class="wnp-bp__n"><?php echo $i; ?></div>
            <div class="wnp-bp__f">
                <input type="text" id="wnp-a<?php echo $i; ?>l" class="wnp-inp" placeholder="<?php esc_attr_e( 'Button Label', 'wp-native-push' ); ?>" maxlength="20" />
                <input type="url"  id="wnp-a<?php echo $i; ?>u" class="wnp-inp" placeholder="https://..." />
            </div>
        </div>
        <?php endfor; ?>
    </div>
</div>

<!-- ▌Card: Delivery Options ▐ -->
<div class="wnp-c">
    <div class="wnp-c__h" data-c="delivery">
        <span>&#x23F0;</span> <?php esc_html_e( 'Delivery Options', 'wp-native-push' ); ?>
        <span class="wnp-c__arr">&#9660;</span>
    </div>
    <div class="wnp-c__b" id="wnp-c-delivery" style="display:none">
        <div class="wnp-del-opts">
            <label class="wnp-rc wnp-rc--on">
                <input type="radio" name="delivery" value="now" checked style="display:none" />
                <span class="wnp-rc__i">&#x26A1;</span>
                <span class="wnp-rc__t"><?php esc_html_e( 'Send Now', 'wp-native-push' ); ?></span>
                <span class="wnp-rc__s"><?php esc_html_e( 'Immediately', 'wp-native-push' ); ?></span>
            </label>
            <label class="wnp-rc">
                <input type="radio" name="delivery" value="scheduled" style="display:none" />
                <span class="wnp-rc__i">&#x1F4C5;</span>
                <span class="wnp-rc__t"><?php esc_html_e( 'Schedule', 'wp-native-push' ); ?></span>
                <span class="wnp-rc__s"><?php esc_html_e( 'Pick date & time', 'wp-native-push' ); ?></span>
            </label>
        </div>
        <div id="wnp-sched" style="display:none;margin-top:16px">
            <div class="wnp-sched-row">
                <div><label class="wnp-lbl"><?php esc_html_e( 'Date', 'wp-native-push' ); ?></label>
                    <input type="date" id="wnp-sd" class="wnp-inp" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" /></div>
                <div><label class="wnp-lbl"><?php esc_html_e( 'Time', 'wp-native-push' ); ?></label>
                    <input type="time" id="wnp-st" class="wnp-inp" value="<?php echo esc_attr( gmdate( 'H:i', strtotime( '+1 hour' ) ) ); ?>" /></div>
                <div><label class="wnp-lbl"><?php esc_html_e( 'Timezone', 'wp-native-push' ); ?></label>
                    <select id="wnp-stz" class="wnp-inp">
                        <?php $wp_tz = get_option( 'timezone_string', 'UTC' );
                        foreach ( DateTimeZone::listIdentifiers() as $tz ) :
                            printf('<option value="%s" %s>%s</option>', esc_attr($tz), selected($tz,$wp_tz,false), esc_html($tz));
                        endforeach; ?>
                    </select></div>
            </div>
            <div style="margin-top:12px">
                <label class="wnp-lbl"><?php esc_html_e( 'Recurring', 'wp-native-push' ); ?></label>
                <div class="wnp-pills">
                    <?php foreach ( ['none' => 'None', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $v => $l ) : ?>
                    <label class="wnp-pill <?php echo $v === 'none' ? 'wnp-pill--on' : ''; ?>">
                        <input type="radio" name="recurring" value="<?php echo esc_attr($v); ?>" <?php checked($v,'none'); ?> style="display:none" />
                        <?php echo esc_html($l); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ▌Card: Push Existing Post ▐ -->
<div class="wnp-c">
    <div class="wnp-c__h" data-c="post">
        <span>&#x1F4C4;</span> <?php esc_html_e( 'Push Existing Post', 'wp-native-push' ); ?>
        <span class="wnp-c__sub"><?php esc_html_e( 'Auto-fill from any post', 'wp-native-push' ); ?></span>
        <span class="wnp-c__arr">&#9660;</span>
    </div>
    <div class="wnp-c__b" id="wnp-c-post" style="display:none">
        <div style="position:relative">
            <input type="text" id="wnp-ps" class="wnp-inp" placeholder="<?php esc_attr_e( 'Search posts, reviews, movies…', 'wp-native-push' ); ?>" />
            <span id="wnp-ps-spin" style="display:none;position:absolute;right:10px;top:9px">&#x29D7;</span>
            <div id="wnp-ps-results" class="wnp-psr" style="display:none"></div>
        </div>
        <div id="wnp-ps-selected" class="wnp-pss" style="display:none">
            <img id="wnp-pss-img" src="" alt="" class="wnp-pss__img" />
            <div class="wnp-pss__info">
                <strong id="wnp-pss-title"></strong>
                <p id="wnp-pss-exc"></p>
            </div>
            <button type="button" id="wnp-pss-clear" class="wnp-pss__x">&#x2715;</button>
        </div>
    </div>
</div>

<!-- ▌Card: Advanced Settings ▐ -->
<div class="wnp-c">
    <div class="wnp-c__h" data-c="adv">
        <span>&#x2699;</span> <?php esc_html_e( 'Advanced Settings', 'wp-native-push' ); ?>
        <span class="wnp-c__arr">&#9660;</span>
    </div>
    <div class="wnp-c__b" id="wnp-c-adv" style="display:none">
        <div class="wnp-adv-g">
            <div><label class="wnp-lbl"><?php esc_html_e( 'Priority', 'wp-native-push' ); ?></label>
                <select id="wnp-priority" class="wnp-inp">
                    <option value="high">High Priority</option>
                    <option value="normal" selected>Normal</option>
                    <option value="low">Low Priority</option>
                </select></div>
            <div><label class="wnp-lbl"><?php esc_html_e( 'TTL (seconds)', 'wp-native-push' ); ?></label>
                <input type="number" id="wnp-ttl" class="wnp-inp" value="86400" min="0" max="2419200" />
                <p class="wnp-hint">86400 = 24 hours</p></div>
        </div>
        <div class="wnp-tg-list">
            <?php
            $tgls = [
                'require_interaction' => [ 'Require Interaction', 'Stays until dismissed', false ],
                'silent'              => [ 'Silent Push',         'No sound or vibration', false ],
                'auto_close'          => [ 'Auto-Close (5s)',     'Closes automatically',  true  ],
            ];
            foreach ( $tgls as $k => [$lbl,$hint,$def] ) :
            ?>
            <div class="wnp-tg-row">
                <div><span class="wnp-tg-lbl"><?php echo esc_html($lbl); ?></span><br><span class="wnp-hint"><?php echo esc_html($hint); ?></span></div>
                <label class="wnp-tg">
                    <input type="checkbox" name="<?php echo esc_attr($k); ?>" <?php checked($def); ?> />
                    <span class="wnp-tg__t"><span class="wnp-tg__d"></span></span>
                </label>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ▌Card: UTM Tracking ▐ -->
<div class="wnp-c">
    <div class="wnp-c__h" data-c="utm">
        <span>&#x1F4CA;</span> <?php esc_html_e( 'UTM Tracking', 'wp-native-push' ); ?>
        <span class="wnp-c__sub"><?php esc_html_e( 'Auto-appended to URL', 'wp-native-push' ); ?></span>
        <span class="wnp-c__arr">&#9660;</span>
    </div>
    <div class="wnp-c__b" id="wnp-c-utm" style="display:none">
        <div class="wnp-utm-g">
            <?php foreach (['source'=>'push_notification','medium'=>'push','campaign'=>'','content'=>''] as $k=>$v): ?>
            <div><label class="wnp-lbl"><?php echo esc_html(ucfirst($k)); ?></label>
                <input type="text" id="wnp-utm-<?php echo esc_attr($k); ?>" class="wnp-inp" value="<?php echo esc_attr($v); ?>" placeholder="utm_<?php echo esc_attr($k); ?>" /></div>
            <?php endforeach; ?>
        </div>
        <div class="wnp-utm-prev" id="wnp-utm-prev"></div>
    </div>
</div>

<!-- ▌Card: Test Notification ▐ -->
<div class="wnp-c">
    <div class="wnp-c__h" data-c="test">
        <span>&#x1F9EA;</span> <?php esc_html_e( 'Test Notification', 'wp-native-push' ); ?>
        <span class="wnp-c__arr">&#9660;</span>
    </div>
    <div class="wnp-c__b" id="wnp-c-test" style="display:none">
        <div class="wnp-del-opts" style="max-width:380px">
            <label class="wnp-rc wnp-rc--on wnp-rc--sm">
                <input type="radio" name="test_target" value="first" checked style="display:none" />
                <span class="wnp-rc__t">1st Subscriber</span>
            </label>
            <label class="wnp-rc wnp-rc--sm">
                <input type="radio" name="test_target" value="all" style="display:none" />
                <span class="wnp-rc__t">Up to 3</span>
            </label>
        </div>
        <div id="wnp-test-msg" class="wnp-inline-msg" style="display:none"></div>
        <button type="button" id="wnp-test-btn" class="button button-secondary" style="margin-top:12px" <?php disabled($total_subs,0); ?>>
            &#x1F9EA; <?php esc_html_e( 'Send Test Push', 'wp-native-push' ); ?>
        </button>
    </div>
</div>

</div><!-- .wnp-cl__main -->

<!-- ░░ RIGHT — Preview ░░ -->
<div class="wnp-cl__side">
    <div class="wnp-pv">

        <!-- Preview device tabs -->
        <div class="wnp-pv__tabs">
            <button class="wnp-pvt wnp-pvt--on" data-pv="chrome">&#x1F5A5; Desktop</button>
            <button class="wnp-pvt" data-pv="android">&#x1F4F1; Android</button>
            <button class="wnp-pvt" data-pv="edge">&#x1F310; Edge</button>
        </div>

        <!-- Chrome Desktop -->
        <div class="wnp-pv__view wnp-pv__view--on" id="wnp-pv-chrome">
            <div class="wnp-pvc">
                <div class="wnp-pvc__hd">
                    <img id="pvci" src="" alt="" class="wnp-pvc__ico" />
                    <div class="wnp-pvc__m"><span class="wnp-pvc__s"><?php echo esc_html($site_host); ?></span><span class="wnp-pvc__b">Chrome &#183; Windows</span></div>
                    <span class="wnp-pvc__tm">now</span>
                </div>
                <div class="wnp-pvc__bd">
                    <div>
                        <p class="wnp-pvc__t" id="pvct">Notification Title</p>
                        <p class="wnp-pvc__g" id="pvcg">Your message appears here&#8230;</p>
                    </div>
                    <img id="pvci2" class="wnp-pvc__img" src="" alt="" style="display:none" />
                </div>
                <div class="wnp-pvc__ac" id="pvca" style="display:none">
                    <button class="wnp-pva" id="pvca1"></button>
                    <button class="wnp-pva" id="pvca2"></button>
                </div>
            </div>
        </div>

        <!-- Android -->
        <div class="wnp-pv__view" id="wnp-pv-android">
            <div class="wnp-pvan">
                <div class="wnp-pvan__sb"><span><?php echo esc_html($site_host); ?></span><span>9:41</span></div>
                <div class="wnp-pvan__card">
                    <img id="pvai" src="" alt="" class="wnp-pvan__ico" />
                    <div><p class="wnp-pvan__app"><?php echo esc_html($site_host); ?></p>
                        <p class="wnp-pvan__t" id="pvat">Notification Title</p>
                        <p class="wnp-pvan__g" id="pvag">Your message&#8230;</p></div>
                </div>
                <img id="pvai2" class="wnp-pvan__img" src="" alt="" style="display:none" />
                <div class="wnp-pvan__ac" id="pvaa" style="display:none">
                    <button class="wnp-pvan__btn" id="pvaa1"></button>
                    <button class="wnp-pvan__btn" id="pvaa2"></button>
                </div>
            </div>
        </div>

        <!-- Edge -->
        <div class="wnp-pv__view" id="wnp-pv-edge">
            <div class="wnp-pve">
                <div class="wnp-pve__hd">
                    <img id="pvei" src="" alt="" class="wnp-pve__ico" />
                    <div><p class="wnp-pve__t" id="pvet">Notification Title</p>
                        <p class="wnp-pve__g" id="pveg">Your message&#8230;</p></div>
                </div>
                <div class="wnp-pve__ft"><span><?php echo esc_html($site_host); ?></span><span>&#215;</span></div>
            </div>
        </div>

        <!-- Stats -->
        <div class="wnp-pv__stats">
            <div class="wnp-pvs"><span class="wnp-pvs__n" id="pv-reach"><?php echo esc_html($total_subs); ?></span><span class="wnp-pvs__l">Est. Reach</span></div>
            <div class="wnp-pvs"><span class="wnp-pvs__n"><?php echo esc_html($desktop_count); ?></span><span class="wnp-pvs__l">Desktop</span></div>
            <div class="wnp-pvs"><span class="wnp-pvs__n"><?php echo esc_html($mobile_count); ?></span><span class="wnp-pvs__l">Mobile</span></div>
        </div>
    </div><!-- .wnp-pv -->
</div><!-- .wnp-cl__side -->

</div><!-- .wnp-cl -->

<!-- ══════════ STICKY FOOTER ══════════ -->
<div class="wnp-fb" id="wnp-fb">
    <div class="wnp-fb__l">
        <span id="wnp-fb-msg"></span>
    </div>
    <div class="wnp-fb__r">
        <button type="button" id="wnp-btn-draft" class="button">
            &#x1F4BE; <?php esc_html_e( 'Save Draft', 'wp-native-push' ); ?>
        </button>
        <button type="button" id="wnp-btn-test-f" class="button" <?php disabled($total_subs,0); ?>>
            &#x1F9EA; <?php esc_html_e( 'Send Test', 'wp-native-push' ); ?>
        </button>
        <button type="button" id="wnp-btn-sched" class="button">
            &#x1F4C5; <?php esc_html_e( 'Schedule', 'wp-native-push' ); ?>
        </button>
        <button type="button" id="wnp-btn-send" class="button button-primary wnp-sb" <?php disabled($total_subs,0); ?>>
            <span id="wnp-spin-ico">&#x1F680;</span>
            <?php esc_html_e( 'Send Push Notification', 'wp-native-push' ); ?>
            <span class="wnp-sb__c">(<?php echo esc_html($total_subs); ?>)</span>
        </button>
    </div>
</div>

<!-- ══════════ HISTORY ══════════ -->
<?php if ( ! empty( $recent_jobs ) ) : ?>
<div class="wnp-hist" style="margin-top:32px">
    <div class="wnp-hist__hd">
        <h3 class="wnp-hist__ttl">&#x1F4DC; <?php esc_html_e( 'Notification History', 'wp-native-push' ); ?></h3>
        <input type="text" id="wnp-hist-s" class="wnp-inp" placeholder="<?php esc_attr_e( 'Search history…', 'wp-native-push' ); ?>" style="max-width:220px" />
    </div>
    <table class="wp-list-table widefat fixed striped wnp-table">
        <thead><tr>
            <th><?php esc_html_e('Title','wp-native-push'); ?></th>
            <th><?php esc_html_e('Date','wp-native-push'); ?></th>
            <th><?php esc_html_e('Sent','wp-native-push'); ?></th>
            <th><?php esc_html_e('Failed','wp-native-push'); ?></th>
            <th><?php esc_html_e('Status','wp-native-push'); ?></th>
            <th><?php esc_html_e('Actions','wp-native-push'); ?></th>
        </tr></thead>
        <tbody id="wnp-hist-tb">
        <?php foreach ( $recent_jobs as $job ) : ?>
        <tr data-title="<?php echo esc_attr(strtolower($job['notification']['title'])); ?>">
            <td><strong><?php echo esc_html($job['notification']['title']); ?></strong></td>
            <td><?php echo esc_html(date_i18n('d M Y H:i',$job['queued_at'])); ?></td>
            <td><span class="wnp-hn wnp-hn--ok"><?php echo esc_html($job['sent']); ?></span></td>
            <td><span class="wnp-hn <?php echo $job['failed'] > 0 ? 'wnp-hn--err' : ''; ?>"><?php echo esc_html($job['failed']); ?></span></td>
            <td><span class="wnp-badge wnp-badge--<?php echo esc_attr($job['status']); ?>"><?php echo esc_html(ucfirst($job['status'])); ?></span></td>
            <td>
                <button type="button" class="button button-small wnp-hd" data-n='<?php echo esc_attr(wp_json_encode($job['notification'])); ?>'>
                    &#x1F4CB; Duplicate
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Emoji picker -->
<div class="wnp-ep" id="wnp-ep">
    <?php foreach (['😀','😂','🔥','✅','⭐','🎉','🚀','💡','❤️','👏','🙌','💪','🎯','📣','🛒','🆕','⚡','🌟','🎁','📌','📱','💰','🔔','📢','🏆','👀','🤩','💥','🎊','📈'] as $e): ?>
    <button type="button" class="wnp-ep__b" data-e="<?php echo esc_attr($e); ?>"><?php echo $e; // phpcs:ignore ?></button>
    <?php endforeach; ?>
</div>

<script>
var wnpDK = 'wnp_draft_<?php echo esc_js(md5(home_url())); ?>';
var wnpTS = <?php echo (int) $total_subs; ?>;
</script>
