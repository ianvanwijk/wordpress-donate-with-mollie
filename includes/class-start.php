<?php

class Dmm_Start {

    private $wpdb;

    /**
     * Dmm_Start constructor.
     */
    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        add_action('init', array($this, 'dmm_do_output_buffer'));
        add_action('rest_api_init', function () {
            register_rest_route('doneren-met-mollie/v1', '/webhook/', array(
                'methods' => 'POST',
                'callback' => array($this, 'dmm_webhook'),
            ));
        });

        add_filter('plugin_action_links_' . DMM_PLUGIN_BASE, array($this, 'dmm_settings_links'));

        add_shortcode('doneren_met_mollie', array($this, 'dmm_donate_form'));

        // Variable translations
        __('iDEAL', DMM_TXT_DOMAIN);
        __('Creditcard', DMM_TXT_DOMAIN);
        __('Bancontact/Mister Cash', DMM_TXT_DOMAIN);
        __('SOFORT Banking', DMM_TXT_DOMAIN);
        __('Bank transfer', DMM_TXT_DOMAIN);
        __('SEPA Direct Debit', DMM_TXT_DOMAIN);
        __('Belfius Direct Net', DMM_TXT_DOMAIN);
        __('PayPal', DMM_TXT_DOMAIN);
        __('Bitcoin', DMM_TXT_DOMAIN);
        __('PODIUM Cadeaukaart', DMM_TXT_DOMAIN);
        __('Paysafecard', DMM_TXT_DOMAIN);
    }

    /**
     * Install/upgrade database
     *
     * @since 1.0.0
     */
    public function dmm_install_database() {
        $charset_collate = $this->wpdb->get_charset_collate();
        $table_name = DMM_TABLE_DONATIONS;
        $table_donors = DMM_TABLE_DONORS;
        $table_subscriptions = DMM_TABLE_SUBSCRIPTIONS;

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $sqlDonations = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            dm_amount float(15) NOT NULL,
            payment_id varchar(45) NOT NULL,
            customer_id varchar(45),
            subscription_id varchar(45),
            payment_method varchar(45) NOT NULL,
            payment_mode varchar(45) NOT NULL,
            donation_id varchar(45) NOT NULL,
            dm_status varchar(25) NOT NULL,
            dm_name varchar(255) NOT NULL,
            dm_email varchar(255) NOT NULL,
            dm_phone varchar(255) NOT NULL,
            dm_company varchar(255) NOT NULL,
            dm_project varchar(255) NOT NULL,
            dm_address varchar(255) NOT NULL,
            dm_zipcode varchar(255) NOT NULL,
            dm_city varchar(255) NOT NULL,
            dm_country varchar(255) NOT NULL,
            dm_message text NOT NULL,
            UNIQUE KEY id (id)
        ) $charset_collate;";
        dbDelta($sqlDonations);

        $sqlDonors = "CREATE TABLE $table_donors (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            customer_id varchar(45) NOT NULL,
            customer_mode varchar(45) NOT NULL,
            customer_name varchar(255) NOT NULL,
            customer_email varchar(255) NOT NULL,
            sub_interval varchar(255) NOT NULL,
            sub_amount float(15) NOT NULL,
            sub_description varchar(255) NOT NULL,
            customer_locale varchar(15) NOT NULL,
            secret varchar(45) NOT NULL,
            UNIQUE KEY id (id)
        ) $charset_collate;";
        dbDelta($sqlDonors);

        $sqlSubscriptions = "CREATE TABLE $table_subscriptions (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            subscription_id varchar(45) NOT NULL,
            customer_id varchar(45) NOT NULL,
            sub_mode varchar(45) NOT NULL,
            sub_amount float(15) NOT NULL,
            sub_times int(9) NOT NULL,
            sub_interval varchar(45) NOT NULL,
            sub_description varchar(255) NOT NULL,
            sub_method varchar(45) NOT NULL,
            sub_status varchar(25) NOT NULL,
            created_at timestamp NOT NULL,
            UNIQUE KEY id (id)
        ) $charset_collate;";
        dbDelta($sqlSubscriptions);
    }

    /**
     * Settings link in plugin list
     *
     * @since 1.0.0
     * @param $links
     * @return mixed
     */
    public function dmm_settings_links($links) {
        $settings_link = '<a href="admin.php?page=' . DMM_PAGE_SETTINGS . '">' . __('Settings', DMM_TXT_DOMAIN) . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Output buffer for redirects
     *
     * @since 1.0.0
     */
    public function dmm_do_output_buffer() {
        ob_start();
    }

    /**
     * Donation form
     *
     * @since 1.0.0
     * @return string
     */
    public function dmm_donate_form()
    {
        ob_start();

        try {
            // Connect with Mollie
            $mollie = new Mollie_API_Client;
            if (get_option('dmm_mollie_apikey'))
                $mollie->setApiKey(get_option('dmm_mollie_apikey'));
            else
                return __('No API-key set', DMM_TXT_DOMAIN);


            $dmm_url_site = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']=='on'?'https://':'http://') . $_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'] . (strstr($_SERVER['REQUEST_URI'], '?') ? '&' : '?');
            $dmm_webhook = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']=='on'?'https://':'http://') . $_SERVER['HTTP_HOST'] . '/?rest_route=/doneren-met-mollie/v1/webhook';
            $dmm_fields = get_option('dmm_form_fields');

            // Submit form, add donation
            if (isset($_POST['dmm_submitted'])) {

                // Validation
                $errors = array();
                if (($dmm_fields['Name']['required'] || $_POST['dmm_recurring_interval'] != 'one') && empty($_POST['dmm_name']))
                    $errors[] = __('Your name is required', DMM_TXT_DOMAIN);

                if (($dmm_fields['Email address']['required'] || $_POST['dmm_recurring_interval'] != 'one') && empty($_POST['dmm_email']))
                    $errors[] = __('Your email address is required', DMM_TXT_DOMAIN);

                if ($_POST['dmm_recurring_interval'] != 'one' && !isset($_POST['dmm_permission']))
                    $errors[] = __('Please give authorization to collect from your account', DMM_TXT_DOMAIN);

                if ($dmm_fields['Phone number']['required'] && empty($_POST['dmm_phone']))
                    $errors[] = __('Your phone number is required', DMM_TXT_DOMAIN);

                if ($dmm_fields['Company name']['required'] && empty($_POST['dmm_company']))
                    $errors[] = __('Your company name is required', DMM_TXT_DOMAIN);

                if ($dmm_fields['Address']['required'] && empty($_POST['dmm_address']))
                    $errors[] = __('Your address is required', DMM_TXT_DOMAIN);

                if ($dmm_fields['Address']['required'] && empty($_POST['dmm_city']))
                    $errors[] = __('Your city is required', DMM_TXT_DOMAIN);

                if ($dmm_fields['Address']['required'] && empty($_POST['dmm_zipcode']))
                    $errors[] = __('Your zipcode is required', DMM_TXT_DOMAIN);

                if ($dmm_fields['Address']['required'] && empty($_POST['dmm_country']))
                    $errors[] = __('Your country is required', DMM_TXT_DOMAIN);

                if ($dmm_fields['Message']['required'] && empty($_POST['dmm_message']))
                    $errors[] = __('A message is required', DMM_TXT_DOMAIN);

                if (empty($_POST['dmm_amount']))
                    $errors[] = __('Please choose an amount', DMM_TXT_DOMAIN);

                if ($_POST['dmm_amount'] < (float)get_option('dmm_minimum_amount', 1))
                    $errors[] = __('The amount is too low, please choose a higher amount', DMM_TXT_DOMAIN);

                if (!empty($errors))
                {
                    echo '<ul>';
                    foreach ($errors as $error) {
                        echo '<li style="color: red;">' . $error . '</li>';
                    }
                    echo '</ul><br>';
                }
                else
                {
                    $donation_id = uniqid(rand(1,99));
                    $amount = number_format(str_replace(',', '.', $_POST['dmm_amount']), 2, '.', '');
                    $description = str_replace(
                        array(
                            '{id}',
                            '{name}',
                            '{project}',
                            '{amount}',
                            '{company}'
                        ),
                        array(
                            $donation_id,
                            $_POST['dmm_name'],
                            $_POST['dmm_project'],
                            $amount,
                            $_POST['dmm_company']
                        ),
                        get_option('dmm_payment_description')
                    );


                    if ($_POST['dmm_recurring_interval'] == 'one')
                    {
                        // One-time donation
                        $payment = $mollie->payments->create(array(
                            "amount"        => $amount,
                            "description"   => $description,
                            "redirectUrl"   => $dmm_url_site . 'donation=' . $donation_id,
                            "webhookUrl"    => $dmm_webhook,
                            "method"        => $_POST['dmm_method'],
                            "metadata"      => array(
                                "name"          => $_POST['dmm_name'],
                                "email"         => $_POST['dmm_email'],
                                "company"       => $_POST['dmm_company'],
                                "donation_id"   => $donation_id,
                            )
                        ));
                    }
                    else
                    {
                        $secret = uniqid();
                        $customer = $mollie->customers->create(array(
                            "name"  => $_POST['dmm_name'],
                            "email" => $_POST['dmm_email'],
                        ));

                        $this->wpdb->query($this->wpdb->prepare("INSERT INTO " . DMM_TABLE_DONORS . "
                    ( customer_id, customer_mode, customer_name, customer_email, sub_interval, sub_amount, sub_description, customer_locale, secret )
                    VALUES ( %s, %s, %s, %s, %s, %s, %s, %s, %s )",
                            $customer->id,
                            $customer->mode,
                            $customer->name,
                            $customer->email,
                            $_POST['dmm_recurring_interval'],
                            $amount,
                            $description,
                            $customer->locale,
                            $secret
                        ));

                        $firstAmount = ($_POST['dmm_method'] == 'mistercash' ? 0.02 : 0.01);
                        $payment = $mollie->payments->create(array(
                            'amount'        => $firstAmount,
                            'customerId'    => $customer->id,
                            'recurringType' => 'first',
                            "description"   => $description,
                            "redirectUrl"   => $dmm_url_site . 'donation=' . $donation_id,
                            "webhookUrl"    => $dmm_webhook . '&first=' . $this->wpdb->insert_id . '&secret=' . $secret,
                            "method"        => $_POST['dmm_method'],
                            "metadata"      => array(
                                "donation_id"   => $donation_id,
                            )
                        ));
                    }

                    $this->wpdb->query($this->wpdb->prepare("INSERT INTO " . DMM_TABLE_DONATIONS . "
                    ( `time`, payment_id, customer_id, donation_id, dm_status, dm_amount, dm_name, dm_email, dm_project, dm_company, dm_address, dm_zipcode, dm_city, dm_country, dm_message, dm_phone, payment_method, payment_mode )
                    VALUES ( %s, %s, %s, %s, 'open', %f, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s )",
                        date('Y-m-d H:i:s'),
                        $payment->id,
                        (isset($customer) ? $customer->id : null),
                        $donation_id,
                        isset($firstAmount) ? $firstAmount : $amount,
                        isset($_POST['dmm_name']) ? $_POST['dmm_name'] : null,
                        isset($_POST['dmm_email']) ? $_POST['dmm_email'] : null,
                        isset($_POST['dmm_project']) ? $_POST['dmm_project'] : null,
                        isset($_POST['dmm_company']) ? $_POST['dmm_company'] : null,
                        isset($_POST['dmm_address']) ? $_POST['dmm_address'] : null,
                        isset($_POST['dmm_zipcode']) ? $_POST['dmm_zipcode'] : null,
                        isset($_POST['dmm_city']) ? $_POST['dmm_city'] : null,
                        isset($_POST['dmm_country']) ? $_POST['dmm_country'] : null,
                        isset($_POST['dmm_message']) ? $_POST['dmm_message'] : null,
                        isset($_POST['dmm_phone']) ? $_POST['dmm_phone'] : null,
                        $payment->method,
                        $payment->mode
                    ));

                    wp_redirect($payment->getPaymentUrl());
                    exit;
                }

            }

            // Webhook and return page
            if (isset($_GET['donation'])) {
                $donation = $this->wpdb->get_row("SELECT * FROM " . DMM_TABLE_DONATIONS . " WHERE donation_id = '" . esc_sql($_GET['donation']) . "'");
                $payment = $mollie->payments->get($donation->payment_id);


                if ($payment->status == 'paid')
                {
                    if (!isset($_GET['redirect']))
                    {
                        wp_redirect(get_option('dmm_redirect_success') != '-1' ? site_url(get_option('dmm_redirect_success')) : $dmm_url_site . 'redirect=true');
                        exit;
                    }

                    echo '<p class="' . esc_attr(get_option('dmm_success_cls', DMM_SUCCESS_CLS)) . '">' . esc_html(get_option('dmm_success_msg', DMM_SUCCESS_MSG)) . '</p>';
                }
                else
                {
                    if (!isset($_GET['redirect']))
                    {
                        wp_redirect(get_option('dmm_redirect_failure') != '-1' ? site_url(get_option('dmm_redirect_failure')) : $dmm_url_site . 'redirect=true');
                        exit;
                    }

                    echo '<p class="' . esc_attr(get_option('dmm_failure_cls', DMM_FAILURE_CLS)) . '">' . esc_html(get_option('dmm_failure_msg', DMM_FAILURE_MSG)) . '</p>';
                }
            } else {
                // Donation form

                $intervals = get_option('dmm_recurring_interval');
                ?>
                <form action="<?php echo esc_url($_SERVER['REQUEST_URI']);?>" class="<?php echo esc_attr(get_option('dmm_form_cls'));?>" method="post">

                    <?php if (get_option('dmm_recurring')) { ?>
                        <p>
                            <select id="dmm_interval" name="dmm_recurring_interval" style="width: 100%" class="<?php echo esc_attr(get_option('dmm_fields_cls'));?>" onchange="dmm_recurring_methods(this.value);">
                                <option value="one"><?php echo esc_html_e('One-time donation', DMM_TXT_DOMAIN);?></option>
                                <?php if ($intervals['month']) { ?>
                                    <option value="month" <?php echo ($_POST['dmm_recurring_interval'] == 'month' ? 'selected' : '');?>><?php echo esc_html_e('Monthly', DMM_TXT_DOMAIN);?></option>
                                <?php } ?>
                                <?php if ($intervals['quarter']) { ?>
                                    <option value="quarter" <?php echo ($_POST['dmm_recurring_interval'] == 'quarter' ? 'selected' : '');?>><?php echo esc_html_e('Each quarter', DMM_TXT_DOMAIN);?></option>
                                <?php } ?>
                                <?php if ($intervals['year']) { ?>
                                    <option value="year" <?php echo ($_POST['dmm_recurring_interval'] == 'year' ? 'selected' : '');?>><?php echo esc_html_e('Annually', DMM_TXT_DOMAIN);?></option>
                                <?php } ?>
                            </select>
                        </p>
                    <?php } else { ?>
                        <input type="hidden" name="dmm_recurring_interval" value="one">
                    <?php } ?>

                    <?php if ($dmm_fields['Name']['active']) { ?>
                        <p <?php echo ($dmm_fields['Name']['active'] ? '' : 'style="display:none"');?>>
                            <?php echo esc_html_e('Name', DMM_TXT_DOMAIN) . ($dmm_fields['Name']['required'] ? '<span style="color:red;">*</span>' : '') . '<br>';?>
                            <input type="text" name="dmm_name" class="<?php echo esc_attr(get_option('dmm_fields_cls'));?>" value="<?php echo (isset($_POST["dmm_name"]) ? esc_attr($_POST["dmm_name"]) : '');?>" style="width: 100%">
                        </p>
                    <?php } ?>

                    <?php if ($dmm_fields['Company name']['active']) { ?>
                        <p>
                            <?php echo esc_html_e('Company name', DMM_TXT_DOMAIN) . ($dmm_fields['Company name']['required'] ? '<span style="color:red;">*</span>' : '') . '<br>';?>
                            <input type="text" name="dmm_company" class="<?php echo esc_attr(get_option('dmm_fields_cls'));?>" value="<?php echo (isset($_POST["dmm_company"]) ? esc_attr($_POST["dmm_company"]) : '');?>" style="width: 100%">
                        </p>
                    <?php } ?>

                    <?php if ($dmm_fields['Email address']['active']) { ?>
                        <p <?php echo ($dmm_fields['Email address']['active'] ? '' : 'style="display:none"');?>>
                            <?php echo esc_html_e('Email address', DMM_TXT_DOMAIN) . ($dmm_fields['Email address']['required'] ? '<span style="color:red;">*</span>' : '') . '<br>';?>
                            <input type="text" name="dmm_email" class="<?php echo esc_attr(get_option('dmm_fields_cls'));?>" value="<?php echo (isset($_POST["dmm_email"]) ? esc_attr($_POST["dmm_email"]) : '');?>" style="width: 100%">
                        </p>
                    <?php } ?>

                    <?php if ($dmm_fields['Phone number']['active']) { ?>
                        <p>
                            <?php echo esc_html_e('Phone number', DMM_TXT_DOMAIN) . ($dmm_fields['Phone number']['required'] ? '<span style="color:red;">*</span>' : '') . '<br>';?>
                            <input type="text" name="dmm_phone" class="<?php echo esc_attr(get_option('dmm_fields_cls'));?>" value="<?php echo (isset($_POST["dmm_phone"]) ? esc_attr($_POST["dmm_phone"]) : '');?>" style="width: 100%">
                        </p>
                    <?php } ?>

                    <?php if ($dmm_fields['Address']['active']) { ?>
                        <p>
                            <?php echo esc_html_e('Address', DMM_TXT_DOMAIN) . ($dmm_fields['Address']['required'] ? '<span style="color:red;">*</span>' : '') . '<br>';?>
                            <input type="text" name="dmm_address" class="<?php echo esc_attr(get_option('dmm_fields_cls'));?>" value="<?php echo (isset($_POST["dmm_address"]) ? esc_attr($_POST["dmm_address"]) : '');?>" style="width: 100%">
                        </p>
                        <p>
                            <?php echo esc_html_e('Zipcode', DMM_TXT_DOMAIN) . ($dmm_fields['Address']['required'] ? '<span style="color:red;">*</span>' : '') . '<br>';?>
                            <input type="text" name="dmm_zipcode" class="<?php echo esc_attr(get_option('dmm_fields_cls'));?>" value="<?php echo (isset($_POST["dmm_zipcode"]) ? esc_attr($_POST["dmm_zipcode"]) : '');?>" style="width: 100%">
                        </p>
                        <p>
                            <?php echo esc_html_e('City', DMM_TXT_DOMAIN) . ($dmm_fields['Address']['required'] ? '<span style="color:red;">*</span>' : '') . '<br>';?>
                            <input type="text" name="dmm_city" class="<?php echo esc_attr(get_option('dmm_fields_cls'));?>" value="<?php echo (isset($_POST["dmm_city"]) ? esc_attr($_POST["dmm_city"]) : '');?>" style="width: 100%">
                        </p>
                        <p>
                            <?php echo esc_html_e('Country', DMM_TXT_DOMAIN) . ($dmm_fields['Address']['required'] ? '<span style="color:red;">*</span>' : '') . '<br>';?>
                            <input type="text" name="dmm_country" class="<?php echo esc_attr(get_option('dmm_fields_cls'));?>" value="<?php echo (isset($_POST["dmm_country"]) ? esc_attr($_POST["dmm_country"]) : '');?>" style="width: 100%">
                        </p>
                    <?php } ?>

                    <?php if ($dmm_fields['Project']['active']) { ?>
                        <p>
                            <?php echo esc_html_e('Project', DMM_TXT_DOMAIN) . ($dmm_fields['Project']['required'] ? '<span style="color:red;">*</span>' : '') . '<br>';?>
                            <?php echo $this->dmm_projects(isset($_POST["dmm_project"]) ? esc_attr($_POST["dmm_project"]) : '');?>
                        </p>
                    <?php } ?>

                    <?php if ($dmm_fields['Message']['active']) { ?>
                        <p>
                            <?php echo esc_html_e('Message', DMM_TXT_DOMAIN) . ($dmm_fields['Message']['required'] ? '<span style="color:red;">*</span>' : '') . '<br>';?>
                            <textarea name="dmm_message" class="<?php echo esc_attr(get_option('dmm_fields_cls'));?>" rows="5" style="width: 100%"><?php echo (isset($_POST["dmm_message"]) ? esc_attr($_POST["dmm_message"]) : '');?></textarea>
                        </p>
                    <?php } ?>

                    <p>
                        <?php
                        echo esc_html_e('Amount', DMM_TXT_DOMAIN) . ' &euro;<span style="color:red;">*</span><br>';

                        if (get_option('dmm_amount'))
                        {
                            if (get_option('dmm_free_input'))
                            {
                                echo '<select id="dmm_dd" style="width: 100%" class="' . esc_attr(get_option('dmm_fields_cls')) . '" onchange="if(this.value!=\'--\'){document.getElementById(\'dmm_amount\').value=this.value;document.getElementById(\'dmm_amount\').style.display = \'none\';}else{document.getElementById(\'dmm_amount\').style.display = \'block\';}">';
                                echo '<option value="--">' . esc_html__('Enter your own amount', DMM_TXT_DOMAIN) . '</option>';
                            }
                            else
                            {
                                echo '<select style="width: 100%" name="dmm_amount" class="' . esc_attr(get_option('dmm_fields_cls')) . '" >';
                            }

                            foreach (explode('/', get_option('dmm_amount')) as $amount) {
                                echo '<option value="' . trim(esc_attr($amount)) . '"' . (get_option('dmm_default_amount') == trim($amount) ? ' selected' : '') . '>&euro; ' . esc_html($amount) . '</option>';
                            }
                            echo '</select>';
                        }

                        if (get_option('dmm_free_input'))
                        {
                            echo '<input type="text" id="dmm_amount" name="dmm_amount" class="' . esc_attr(get_option('dmm_fields_cls')) . '" value="' . (isset($_POST["dmm_amount"]) ? esc_attr($_POST["dmm_amount"]) : get_option('dmm_default_amount')) . '" style="width: 100%">';
                        }
                        ?>
                    </p>

                    <?php echo $this->dmm_payment_methods($mollie);?>

                    <br>
                    <?php echo '<script>window.onload=function(){if(document.getElementById(\'dmm_interval\').value!=\'one\'){document.getElementById(\'dmm_permission\').style.display = \'block\';}if(document.getElementById(\'dmm_dd\').value!=\'--\'){document.getElementById(\'dmm_amount\').value=document.getElementById(\'dmm_dd\').value;document.getElementById(\'dmm_amount\').style.display = \'none\';} dmm_recurring_methods(document.getElementById(\'dmm_interval\').value);}</script>';?>
                    <label id="dmm_permission" style="display:none"><input type="checkbox" name="dmm_permission"> <?php echo sprintf(__('I hereby authorize %s to collect the amount shown above from my account periodically.', DMM_TXT_DOMAIN), get_option('dmm_name_foundation'));?></label>

                    <br><br>
                    <input type="submit" name="dmm_submitted" class="<?php echo esc_attr(get_option('dmm_button_cls'));?>" value="<?php echo esc_attr(__('Donate', DMM_TXT_DOMAIN));?>">

                </form>
                <?php

            }


        } catch (Mollie_API_Exception $e) {
            echo "API call failed: " . htmlspecialchars($e->getMessage());
        }

        $output = ob_get_clean();
        return $output;
    }

    /**
     * Payment methods
     *
     * @since 2.0.0
     * @param $mollie
     * @return string
     */
    private function dmm_payment_methods($mollie) {
        $option = get_option('dmm_methods_display', 'list');

        $recurring = array('dd' => false, 'cc' => false);
        foreach ($mollie->methods->all() as $method)
        {
            if ($method->id == 'directdebit')$recurring['dd'] = true;
            if ($method->id == 'creditcard')$recurring['cc'] = true;
        }

        $scriptCC = '';
        if (!$recurring['cc'])
        {
            $scriptCC = '
                var x = document.getElementsByClassName("dmm_cc");
                var i;
                for (i = 0; i < x.length; i++) {
                    x[i].style.display = value!="one" ? "none" : "block";
                    x[i].disabled = value!="one" ? "disabled" : "";
                }';
        }

        $scriptDD = '';
        if (!$recurring['dd'])
        {
            $scriptDD = '
                var x = document.getElementsByClassName("dmm_dd");
                var i;
                for (i = 0; i < x.length; i++) {
                    x[i].style.display = value!="one" ? "none" : "block";
                    x[i].disabled = value!="one" ? "disabled" : "";
                }';
        }

        $methods = '
            <script>
            function dmm_recurring_methods(value) {
                var x = document.getElementsByClassName("dmm_recurring");
                var i;
                for (i = 0; i < x.length; i++) {
                    x[i].style.display = value!="one" ? "none" : "block";
                    x[i].disabled = value!="one" ? "disabled" : "";
                }
                ' . $scriptCC . $scriptDD . '
                document.getElementById("dmm_permission").style.display = (value=="one" ? "none" : "block");
            }
            </script>';

        if ($option == 'list')
        {
            foreach ($mollie->methods->all() as $method)
            {
                $methods .=  '<label ' . $this->dmm_recurring_method($method->id) . '><input type="radio" name="dmm_method" value="' . $method->id . '"> <img style="vertical-align:middle;display:inline-block" src="' . esc_url($method->image->normal) . '"> ' . esc_html__($method->description, DMM_TXT_DOMAIN) . '<br></label>';
            }
        }
        elseif ($option == 'list_no_icons')
        {
            foreach ($mollie->methods->all() as $method)
            {
                $methods .=  '<label ' . $this->dmm_recurring_method($method->id) . '><input type="radio" name="dmm_method" value="' . $method->id . '"> ' . esc_html__($method->description, DMM_TXT_DOMAIN) . '<br></label>';
            }
        }
        elseif ($option == 'list_icons')
        {
            foreach ($mollie->methods->all() as $method)
            {
                $methods .=  '<label ' . $this->dmm_recurring_method($method->id) . '><input type="radio" name="dmm_method" value="' . $method->id . '"> <img style="vertical-align:middle;display:inline-block" src="' . esc_url($method->image->normal) . '"></label> ';
            }
        }
        elseif ($option == 'dropdown')
        {
            $methods .= '<select style="width: 100%" name="dmm_method" class="' . esc_attr(get_option('dmm_fields_cls')) . '">';
            $methods .= '<option value="">== ' . esc_html__('Choose a payment method', DMM_TXT_DOMAIN) . ' ==</option>';
            foreach ($mollie->methods->all() as $method)
            {
                $methods .=  '<option ' . $this->dmm_recurring_method($method->id) . ' value="' . $method->id . '">' . esc_html__($method->description, DMM_TXT_DOMAIN) . '</option>';
            }
            $methods .= '</select>';
        }


        return $methods;
    }

    /**
     * Recurring method
     *
     * @since 2.1.1
     * @param $id
     * @return string
     */
    private function dmm_recurring_method($id)
    {
        $recurring = array('ideal', 'mistercash', 'belfius', 'sofort', 'creditcard');

        return !in_array($id, $recurring) ? 'class="dmm_recurring"' : 'class="' . ($id == 'creditcard' ? 'dmm_cc' : 'dmm_dd') . '"';
    }

    /**
     * Project list
     *
     * @since 2.0.0
     * @param $selected
     * @return string
     */
    private function dmm_projects($selected = '') {
        $projects = explode(PHP_EOL, get_option('dmm_projects'));

        $projectList = '<select style="width: 100%" name="dmm_project" class="' . esc_attr(get_option('dmm_fields_cls')) . '">';
        $projectList .= '<option>' . esc_html__('General') . '</option>';
        foreach ($projects as $project)
        {
            $projectList .= '<option' . ($selected == $project ? ' selected' : '') . '>' . esc_attr($project) . '</option>';
        }
        $projectList .= '</select>';

        return $projectList;
    }

    /**
     * Get interval for subscription
     *
     * @since 2.1.0
     * @param $string
     * @return string
     */
    private function dmm_get_interval($string)
    {
        switch ($string) {
            case 'month':
                $interval = '1 month';
                break;
            case 'quarter':
                $interval = '3 months';
                break;
            case 'year':
                $interval = '12 months';
                break;
        }

        return $interval;
    }

    /**
     * Webhook
     *
     * @since 2.1.0
     * @param $args
     * @return string
     */
    public function dmm_webhook($args)
    {
        $dmm_webhook = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']=='on'?'https://':'http://') . $_SERVER['HTTP_HOST'] . '/?rest_route=/doneren-met-mollie/v1/webhook';

        try {
            // Connect with Mollie
            $mollie = new Mollie_API_Client;
            if (get_option('dmm_mollie_apikey'))
                $mollie->setApiKey(get_option('dmm_mollie_apikey'));
            else
                return new WP_Error('dmm_no_api_key', 'No API-key set', array('status' => 400));

            if (!isset($args['sub']))
            {
                // First payment of recurring donation or one-time donation
                $payment_id = $_POST['id'];
                if (!$payment_id)
                    return new WP_Error('dmm_no_payment_id', 'No payment id', array('status' => 404));

                $donation = $this->wpdb->get_row("SELECT * FROM " . DMM_TABLE_DONATIONS . " WHERE payment_id = '" . esc_sql($payment_id) . "'");

                if (!$donation->id)
                    return new WP_Error('dmm_donation_not_found', 'Donation not found', array('status' => 404));

                $payment = $mollie->payments->get($payment_id);
                $this->wpdb->query($this->wpdb->prepare("UPDATE " . DMM_TABLE_DONATIONS . " SET dm_status = %s, payment_method = %s, payment_mode = %s, customer_id = %s, subscription_id = %s WHERE id = %d",
                    $payment->status,
                    $payment->method,
                    $payment->mode,
                    $payment->customerId,
                    $payment->subscriptionId,
                    $donation->id
                ));


                if (isset($args['first'], $args['secret']) && ($payment->isPaid() && !$payment->isRefunded()))
                {
                    $customer = $this->wpdb->get_row("SELECT * FROM " . DMM_TABLE_DONORS . " WHERE id = '" . esc_sql($args['first']) . "' AND secret='" . esc_sql($args['secret']) . "'");

                    if (!$customer->id)
                        return new WP_Error('dmm_customer_not_found', 'Customer not found', array('status' => 404));

                    $this->wpdb->query($this->wpdb->prepare("INSERT INTO " . DMM_TABLE_SUBSCRIPTIONS . "
                    ( customer_id, created_at )
                    VALUES ( %s, NOW())",
                        $customer->id
                    ));

                    $sub_id = $this->wpdb->insert_id;
                    $subscription = $mollie->customers_subscriptions->withParentId($customer->customer_id)->create(array(
                        "amount"      => $customer->sub_amount,
                        "interval"    => $this->dmm_get_interval($customer->sub_interval),
                        "description" => $customer->sub_description,
                        "webhookUrl"  => $dmm_webhook . '&sub=' . $sub_id,
                    ));

                    $this->wpdb->query($this->wpdb->prepare("UPDATE " . DMM_TABLE_SUBSCRIPTIONS . " SET subscription_id = %s, sub_mode = %s, sub_amount = %s, sub_times = %s, sub_interval = %s, sub_description = %s, sub_method = %s, sub_status = %s WHERE id = %d",
                        $subscription->id,
                        $subscription->mode,
                        $subscription->amount,
                        $subscription->times,
                        $subscription->interval,
                        $subscription->description,
                        $subscription->method,
                        $subscription->status,
                        $sub_id
                    ));
                }

                return 'OK, ' . $payment_id;
            }
            else
            {
                // Subscription
                $sub = $this->wpdb->get_row("SELECT * FROM " . DMM_TABLE_SUBSCRIPTIONS . " WHERE id = '" . esc_sql($args['sub']) . "'");
                if (!$sub->id)
                    return new WP_Error('dmm_subscription_not_found', 'Subscription not found', array('status' => 404));


                $firstDonation = $this->wpdb->get_row("SELECT * FROM " . DMM_TABLE_DONATIONS . " WHERE subscription_id = '" . esc_sql($sub->subscription_id) . "'");
                if (!$firstDonation->id)
                    return new WP_Error('dmm_donation_not_found', 'Donation not found', array('status' => 404));

                $payment_id = $_POST['id'];
                if (!$payment_id)
                    return new WP_Error('dmm_no_payment_id', 'No payment id', array('status' => 404));


                $donation_id = uniqid(rand(1,99));
                $payment = $mollie->payments->get($payment_id);

                $donation = $this->wpdb->get_row("SELECT * FROM " . DMM_TABLE_DONATIONS . " WHERE payment_id = '" . esc_sql($payment->id) . "'");
                if (!$donation->id)
                {
                    // New payment
                    $this->wpdb->query($this->wpdb->prepare("INSERT INTO " . DMM_TABLE_DONATIONS . "
                    ( `time`, payment_id, customer_id, subscription_id, donation_id, dm_status, dm_amount, dm_name, dm_email, dm_project, dm_company, dm_address, dm_zipcode, dm_city, dm_country, dm_message, dm_phone, payment_method, payment_mode )
                    VALUES ( %s, %s, %s, %s, %s, %s, %f, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s )",
                        date('Y-m-d H:i:s'),
                        $payment->id,
                        $payment->customerId,
                        $payment->subscriptionId,
                        $donation_id,
                        $payment->status,
                        $payment->amount,
                        $firstDonation->dm_name,
                        $firstDonation->dm_email,
                        $firstDonation->dm_project,
                        $firstDonation->dm_company,
                        $firstDonation->dm_address,
                        $firstDonation->dm_zipcode,
                        $firstDonation->dm_city,
                        $firstDonation->dm_country,
                        $firstDonation->dm_message,
                        $firstDonation->dm_phone,
                        $payment->method,
                        $payment->mode
                    ));
                }
                else
                {
                    // Update payment
                    $this->wpdb->query($this->wpdb->prepare("UPDATE " . DMM_TABLE_DONATIONS . " SET dm_status = %s, payment_method = %s, payment_mode = %s WHERE payment_id = %s",
                        $payment->status,
                        $payment->method,
                        $payment->mode,
                        $payment->id
                    ));
                }

                return 'OK, ' . $payment_id;
            }

        } catch (Mollie_API_Exception $e) {
            return new WP_Error('dmm_api_call_failed', "API call failed: " . $e->getMessage(), array('status' => 400));
        }
    }
}