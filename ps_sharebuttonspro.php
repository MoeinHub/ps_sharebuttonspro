<?php
/*
* 2007-2020 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* https://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to https://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2020 PrestaShop SA
*  @license    https://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Module\WidgetInterface;

class Ps_Sharebuttonspro extends Module implements WidgetInterface
{
    /**
     * @var string Name of the module running on PS 1.6.x. Used for data migration.
     */
    const PS_16_EQUIVALENT_MODULE = 'socialsharing';

    protected static $networks = ['Telegram', 'Whatsapp', 'Email', 'Copy', 'Other', 'Facebook', 'Twitter', 'Pinterest'];

    private $templateFile;

    public function __construct()
    {
        $this->name = 'ps_sharebuttonspro';
        $this->tab = 'advertising_marketing';
        $this->author = 'PrestaShop';
        $this->version = '2.1.2';
        $this->need_instance = 0;

        $this->ps_versions_compliancy = ['min' => '1.7.1.0', 'max' => _PS_VERSION_];

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->trans('Social media share buttons', [], 'Modules.Sharebuttonspro.Admin');
        $this->description = $this->trans('Instagram, YouTube, gather your community with social media sharing buttons on product pages.', [], 'Modules.Sharebuttonspro.Admin');

        $this->templateFile = 'module:ps_sharebuttonspro/views/templates/hook/ps_sharebuttonspro.tpl';
    }

    public function install()
    {
        if (!$this->uninstallPrestaShop16Module()) {
            Configuration::updateValue('PS_SC_TELEGRAM', 1);
            Configuration::updateValue('PS_SC_WHATSAPP', 1);
            Configuration::updateValue('PS_SC_EMAIL', 1);
            Configuration::updateValue('PS_SC_COPY', 1);
            Configuration::updateValue('PS_SC_OTHER', 1);
            Configuration::updateValue('PS_SC_TWITTER', 1);
            Configuration::updateValue('PS_SC_FACEBOOK', 1);
            Configuration::updateValue('PS_SC_PINTEREST', 1);
        }

        return parent::install()
            && $this->registerHook('displayProductAdditionalInfo')
        ;
    }

    /**
     * Migrate data from 1.6 equivalent module (if applicable), then uninstall
     */
    public function uninstallPrestaShop16Module()
    {
        if (!Module::isInstalled(self::PS_16_EQUIVALENT_MODULE)) {
            return false;
        }
        $oldModule = Module::getInstanceByName(self::PS_16_EQUIVALENT_MODULE);
        if ($oldModule) {
            // This closure calls the parent class to prevent data to be erased
            // It allows the new module to be configured without migration
            $parentUninstallClosure = function () {
                return parent::uninstall();
            };
            $parentUninstallClosure = $parentUninstallClosure->bindTo($oldModule, get_class($oldModule));
            $parentUninstallClosure();
        }

        return true;
    }

    public function getConfigFieldsValues()
    {
        $values = [];

        foreach (self::$networks as $network) {
            $values['PS_SC_' . Tools::strtoupper($network)] = (int) Tools::getValue('PS_SC_' . Tools::strtoupper($network), Configuration::get('PS_SC_' . Tools::strtoupper($network)));
        }

        return $values;
    }

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitSocialSharing')) {
            foreach (self::$networks as $network) {
                Configuration::updateValue('PS_SC_' . Tools::strtoupper($network), (int) Tools::getValue('PS_SC_' . Tools::strtoupper($network)));
            }

            $this->_clearCache($this->templateFile);

            $output .= $this->displayConfirmation($this->trans('Settings updated.', [], 'Admin.Notifications.Success'));

            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true) . '&conf=6&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name);
        }

        $helper = new HelperForm();
        $helper->submit_action = 'submitSocialSharing';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = ['fields_value' => $this->getConfigFieldsValues()];

        $fields = [];
        foreach (self::$networks as $network) {
            $fields[] = [
                'type' => 'switch',
                'label' => $network,
                'name' => 'PS_SC_' . Tools::strtoupper($network),
                'values' => [
                    [
                        'id' => Tools::strtolower($network) . '_active_on',
                        'value' => 1,
                        'label' => $this->trans('Yes', [], 'Admin.Global'),
                    ],
                    [
                        'id' => Tools::strtolower($network) . '_active_off',
                        'value' => 0,
                        'label' => $this->trans('No', [], 'Admin.Global'),
                    ],
                ],
            ];
        }

        return $output . $helper->generateForm([
            [
                'form' => [
                    'legend' => [
                        'title' => $this->displayName,
                        'icon' => 'icon-share',
                    ],
                    'input' => $fields,
                    'submit' => [
                        'title' => $this->trans('Save', [], 'Admin.Actions'),
                    ],
                ],
            ],
        ]);
    }

    public function renderWidget($hookName, array $params)
    {
        $this->smarty->assign($this->getWidgetVariables($hookName, $params));

        return $this->fetch($this->templateFile);
    }

    public function getWidgetVariables($hookName, array $params)
    {
        if (!method_exists($this->context->controller, 'getProduct')) {
            return;
        }

        $product = $this->context->controller->getProduct();

        if (!Validate::isLoadedObject($product)) {
            return;
        }

        $social_share_links = [];
        $sharing_url = urlencode(addcslashes($this->context->link->getProductLink($product), "'"));
        $sharing_name = urlencode(addcslashes($product->name, "'"));

        $image_cover_id = $product->getCover($product->id);
        if (is_array($image_cover_id) && isset($image_cover_id['id_image'])) {
            $image_cover_id = (int) $image_cover_id['id_image'];
        } else {
            $image_cover_id = 0;
        }

        $sharing_img = urlencode(addcslashes($this->context->link->getImageLink($product->link_rewrite, (string) $image_cover_id), "'"));

        if (Configuration::get('PS_SC_FACEBOOK')) {
            $social_share_links['facebook'] = [
                'label' => $this->trans('Share', [], 'Modules.Sharebuttonspro.Shop'),
                'class' => 'facebook',
                'url' => 'https://www.facebook.com/sharer.php?u=' . $sharing_url,
            ];
        }

        if (Configuration::get('PS_SC_TWITTER')) {
            $social_share_links['twitter'] = [
                'label' => $this->trans('Tweet', [], 'Modules.Sharebuttonspro.Shop'),
                'class' => 'twitter',
                'url' => 'https://twitter.com/intent/tweet?text=' . $sharing_name . ' ' . $sharing_url,
            ];
        }

        if (Configuration::get('PS_SC_PINTEREST')) {
            $social_share_links['pinterest'] = [
                'label' => $this->trans('Pinterest', [], 'Modules.Sharebuttonspro.Shop'),
                'class' => 'pinterest',
                'url' => 'https://www.pinterest.com/pin/create/button/?media=' . $sharing_img . '&url=' . $sharing_url,
            ];
        }

        if (Configuration::get('PS_SC_TELEGRAM')) {
            $social_share_links['telegram'] = [
                'label' => $this->trans('Telegram', [], 'Modules.Sharebuttonspro.Shop'),
                'class' => 'telegram',
                'url' => 'https://telegram.me/share/url?url=' . $sharing_img . '&url=' . $sharing_url,
            ];
        }

        if (Configuration::get('PS_SC_WHATSAPP')) {
            $social_share_links['whatsapp'] = [
                'label' => $this->trans('Whatsapp', [], 'Modules.Sharebuttonspro.Shop'),
                'class' => 'whatsapp',
                'url' => 'https://api.whatsapp.com/send?text=' . $sharing_name . ' ' . $sharing_url,
            ];
        }

        if (Configuration::get('PS_SC_EMAIL')) {
            $social_share_links['email'] = [
                'label' => $this->trans('Email', [], 'Modules.Sharebuttonspro.Shop'),
                'class' => 'email',
                'url' => 'mailto://' . $sharing_img . '&url=' . $sharing_url,
            ];
        }

        if (Configuration::get('PS_SC_COPY')) {
            $social_share_links['copy'] = [
                'label' => $this->trans('Copy', [], 'Modules.Sharebuttonspro.Shop'),
                'class' => 'copy',
                'url' => 'copy://' . $sharing_img . '&url=' . $sharing_url,
            ];
        }

        if (Configuration::get('PS_SC_OTHER')) {
            $social_share_links['other'] = [
                'label' => $this->trans('Other', [], 'Modules.Sharebuttonspro.Shop'),
                'class' => 'other',
                'url' => 'other://' . $sharing_img . '&url=' . $sharing_url, $sha
            ];
        }

        return [
            'social_share_links' => $social_share_links,
        ];
    }
}
