<?php
namespace TYPO3\CMS\Install\Service;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Controller\Exception\RedirectException;

/**
 * Execute "silent" LocalConfiguration upgrades if needed.
 *
 * Some LocalConfiguration settings are obsolete or changed over time.
 * This class handles upgrades of these settings. It is called by
 * the step controller at an early point.
 *
 * Every change is encapsulated in one method an must throw a RedirectException
 * if new data is written to LocalConfiguration. This is caught by above
 * step controller to initiate a redirect and start again with adapted configuration.
 */
class SilentConfigurationUpgradeService
{
    /**
     * @var \TYPO3\CMS\Core\Configuration\ConfigurationManager
     */
    protected $configurationManager;

    /**
     * List of obsolete configuration options in LocalConfiguration to be removed
     * Example:
     *    // #forge-ticket
     *    'BE/somesetting',
     *
     * @var array
     */
    protected $obsoleteLocalConfigurationSettings = array(
        // #72367
        'INSTALL/wizardDone/TYPO3\\CMS\\Install\\Updates\\AccessRightParametersUpdate',
        'INSTALL/wizardDone/TYPO3\\CMS\\Install\\Updates\\BackendUserStartModuleUpdate',
        'INSTALL/wizardDone/TYPO3\\CMS\\Install\\Updates\\Compatibility6ExtractionUpdate',
        'INSTALL/wizardDone/TYPO3\\CMS\\Install\\Updates\\ContentTypesToTextMediaUpdate',
        'INSTALL/wizardDone/TYPO3\\CMS\\Install\\Updates\\FileListIsStartModuleUpdate',
        'INSTALL/wizardDone/TYPO3\\CMS\\Install\\Updates\\FilesReplacePermissionUpdate',
        'INSTALL/wizardDone/TYPO3\\CMS\\Install\\Updates\\LanguageIsoCodeUpdate',
        'INSTALL/wizardDone/TYPO3\\CMS\\Install\\Updates\\MediaceExtractionUpdate',
        'INSTALL/wizardDone/TYPO3\\CMS\\Install\\Updates\\MigrateMediaToAssetsForTextMediaCe',
        'INSTALL/wizardDone/TYPO3\\CMS\\Install\\Updates\\MigrateShortcutUrlsAgainUpdate',
        'INSTALL/wizardDone/TYPO3\\CMS\\Install\\Updates\\OpenidExtractionUpdate',
        'INSTALL/wizardDone/TYPO3\\CMS\\Install\\Updates\\PageShortcutParentUpdate',
        'INSTALL/wizardDone/TYPO3\\CMS\\Install\\Updates\\ProcessedFileChecksumUpdate',
        'INSTALL/wizardDone/TYPO3\\CMS\\Install\\Updates\\TableFlexFormToTtContentFieldsUpdate',
        'INSTALL/wizardDone/TYPO3\\CMS\\Install\\Updates\\WorkspacesNotificationSettingsUpdate',
        'INSTALL/wizardDone/TYPO3\\CMS\\Rtehtmlarea\\Hook\\Install\\DeprecatedRteProperties',
        'INSTALL/wizardDone/TYPO3\\CMS\\Rtehtmlarea\\Hook\\Install\\RteAcronymButtonRenamedToAbbreviation',
        // #72400
        'BE/spriteIconGenerator_handler',
        // #72417
        'SYS/lockingMode',
        // #72473
        'FE/secureFormmail',
        'FE/strictFormmail',
        'FE/formmailMaxAttachmentSize',
        // #72337
        'SYS/t3lib_cs_utils',
        'SYS/t3lib_cs_convMethod',
        // #72604
        'SYS/maxFileNameLength',
        // #72602
        'BE/unzip_path',
        // #72615
        'BE/notificationPrefix',
        // #72616
        'BE/XCLASS',
        'FE/XCLASS',
        // #43085
        'GFX/image_processing',
        // #70056
        'SYS/curlUse',
        'SYS/curlProxyNTLM',
        'SYS/curlProxyServer',
        'SYS/curlProxyTunnel',
        'SYS/curlProxyUserPass',
        'SYS/curlTimeout',
    );

    public function __construct(ConfigurationManager $configurationManager = null)
    {
        $this->configurationManager = $configurationManager ?: GeneralUtility::makeInstance(ConfigurationManager::class);
    }

    /**
     * Executed configuration upgrades. Single upgrade methods must throw a
     * RedirectException if something was written to LocalConfiguration.
     *
     * @return void
     */
    public function execute()
    {
        $this->generateEncryptionKeyIfNeeded();
        $this->configureBackendLoginSecurity();
        $this->configureSaltedPasswords();
        $this->migrateImageProcessorSetting();
        $this->transferHttpSettings();
        $this->disableImageMagickDetailSettingsIfImageMagickIsDisabled();
        $this->setImageMagickDetailSettings();
        $this->removeObsoleteLocalConfigurationSettings();
        $this->migrateThumbnailsPngSetting();
    }

    /**
     * Some settings in LocalConfiguration vanished in DefaultConfiguration
     * and have no impact on the core anymore.
     * To keep the configuration clean, those old settings are just silently
     * removed from LocalConfiguration if set.
     *
     * @return void
     */
    protected function removeObsoleteLocalConfigurationSettings()
    {
        $removed = $this->configurationManager->removeLocalConfigurationKeysByPath($this->obsoleteLocalConfigurationSettings);

        // If something was changed: Trigger a reload to have new values in next request
        if ($removed) {
            $this->throwRedirectException();
        }
    }

    /**
     * Backend login security is set to rsa if rsaauth
     * is installed (but not used) otherwise the default value "normal" has to be used.
     * This forces either 'normal' or 'rsa' to be set in LocalConfiguration.
     *
     * @return void
     */
    protected function configureBackendLoginSecurity()
    {
        $rsaauthLoaded = ExtensionManagementUtility::isLoaded('rsaauth');
        try {
            $currentLoginSecurityLevelValue = $this->configurationManager->getLocalConfigurationValueByPath('BE/loginSecurityLevel');
            if ($rsaauthLoaded && $currentLoginSecurityLevelValue !== 'rsa') {
                $this->configurationManager->setLocalConfigurationValueByPath('BE/loginSecurityLevel', 'rsa');
                $this->throwRedirectException();
            } elseif (!$rsaauthLoaded && $currentLoginSecurityLevelValue !== 'normal') {
                $this->configurationManager->setLocalConfigurationValueByPath('BE/loginSecurityLevel', 'normal');
                $this->throwRedirectException();
            }
        } catch (\RuntimeException $e) {
            // If an exception is thrown, the value is not set in LocalConfiguration
            $this->configurationManager->setLocalConfigurationValueByPath('BE/loginSecurityLevel', $rsaauthLoaded ? 'rsa' : 'normal');
            $this->throwRedirectException();
        }
    }

    /**
     * Check the settings for salted passwords extension to load it as a required extension.
     * Unset obsolete configuration options if given.
     *
     * @return void
     */
    protected function configureSaltedPasswords()
    {
        $defaultConfiguration = $this->configurationManager->getDefaultConfiguration();
        $defaultExtensionConfiguration = unserialize($defaultConfiguration['EXT']['extConf']['saltedpasswords']);
        try {
            $extensionConfiguration = @unserialize($this->configurationManager->getLocalConfigurationValueByPath('EXT/extConf/saltedpasswords'));
        } catch (\RuntimeException $e) {
            $extensionConfiguration = array();
        }
        if (is_array($extensionConfiguration) && !empty($extensionConfiguration)) {
            if (isset($extensionConfiguration['BE.']['enabled'])) {
                if ($extensionConfiguration['BE.']['enabled']) {
                    unset($extensionConfiguration['BE.']['enabled']);
                } else {
                    $extensionConfiguration['BE.'] = $defaultExtensionConfiguration['BE.'];
                }
                $this->configurationManager->setLocalConfigurationValueByPath(
                    'EXT/extConf/saltedpasswords',
                    serialize($extensionConfiguration)
                );
                $this->throwRedirectException();
            }
        } else {
            $this->configurationManager->setLocalConfigurationValueByPath(
                'EXT/extConf/saltedpasswords',
                serialize($defaultExtensionConfiguration)
            );
            $this->throwRedirectException();
        }
    }

    /**
     * The encryption key is crucial for securing form tokens
     * and the whole TYPO3 link rendering later on. A random key is set here in
     * LocalConfiguration if it does not exist yet. This might possible happen
     * during upgrading and will happen during first install.
     *
     * @return void
     */
    protected function generateEncryptionKeyIfNeeded()
    {
        try {
            $currentValue = $this->configurationManager->getLocalConfigurationValueByPath('SYS/encryptionKey');
        } catch (\RuntimeException $e) {
            // If an exception is thrown, the value is not set in LocalConfiguration
            $currentValue = '';
        }

        if (empty($currentValue)) {
            $randomKey = GeneralUtility::makeInstance(Random::class)->generateRandomHexString(96);
            $this->configurationManager->setLocalConfigurationValueByPath('SYS/encryptionKey', $randomKey);
            $this->throwRedirectException();
        }
    }

    /**
     * Parse old curl and HTTP options and set new HTTP options, related to Guzzle
     *
     * @return void
     */
    protected function transferHttpSettings()
    {
        $changed = false;
        $newParameters = [];
        $obsoleteParameters = [];

        // Remove / migrate options to new options
        try {
            // Check if the adapter option is set, if so, set it to the parameters that are obsolete
            $this->configurationManager->getLocalConfigurationValueByPath('HTTP/adapter');
            $obsoleteParameters[] = 'HTTP/adapter';
        } catch (\RuntimeException $e) {
        }
        try {
            $newParameters['HTTP/version'] = $this->configurationManager->getLocalConfigurationValueByPath('HTTP/protocol_version');
            $obsoleteParameters[] = 'HTTP/protocol_version';
        } catch (\RuntimeException $e) {
        }
        try {
            $this->configurationManager->getLocalConfigurationValueByPath('HTTP/ssl_verify_host');
            $obsoleteParameters[] = 'HTTP/ssl_verify_host';
        } catch (\RuntimeException $e) {
        }
        try {
            $legacyUserAgent = $this->configurationManager->getLocalConfigurationValueByPath('HTTP/userAgent');
            $newParameters['HTTP/headers/User-Agent'] = $legacyUserAgent;
            $obsoleteParameters[] = 'HTTP/userAgent';
        } catch (\RuntimeException $e) {
        }

        // Redirects
        try {
            $legacyFollowRedirects = $this->configurationManager->getLocalConfigurationValueByPath('HTTP/follow_redirects');
            $obsoleteParameters[] = 'HTTP/follow_redirects';
        } catch (\RuntimeException $e) {
            $legacyFollowRedirects = '';
        }
        try {
            $legacyMaximumRedirects = $this->configurationManager->getLocalConfigurationValueByPath('HTTP/max_redirects');
            $obsoleteParameters[] = 'HTTP/max_redirects';
        } catch (\RuntimeException $e) {
            $legacyMaximumRedirects = '';
        }
        try {
            $legacyStrictRedirects = $this->configurationManager->getLocalConfigurationValueByPath('HTTP/strict_redirects');
            $obsoleteParameters[] = 'HTTP/strict_redirects';
        } catch (\RuntimeException $e) {
            $legacyStrictRedirects = '';
        }

        // Check if redirects have been disabled
        if ($legacyFollowRedirects !== '' && (bool)$legacyFollowRedirects === false) {
            $newParameters['HTTP/allow_redirects'] = false;
        } elseif ($legacyMaximumRedirects !== '' || $legacyStrictRedirects !== '') {
            $newParameters['HTTP/allow_redirects'] = [];
            if ($legacyMaximumRedirects !== '' && (int)$legacyMaximumRedirects !== 5) {
                $newParameters['HTTP/allow_redirects']['max'] = (int)$legacyMaximumRedirects;
            }
            if ($legacyStrictRedirects !== '' && (bool)$legacyStrictRedirects === true) {
                $newParameters['HTTP/allow_redirects']['strict'] = true;
            }
            // defaults are used, no need to set the option in LocalConfiguration.php
            if (empty($newParameters['HTTP/allow_redirects'])) {
                unset($newParameters['HTTP/allow_redirects']);
            }
        }

        // Migrate Proxy settings
        try {
            // Currently without protocol or port
            $legacyProxyHost = $this->configurationManager->getLocalConfigurationValueByPath('HTTP/proxy_host');
            $obsoleteParameters[] = 'HTTP/proxy_host';
        } catch (\RuntimeException $e) {
            $legacyProxyHost = '';
        }
        try {
            $legacyProxyPort = $this->configurationManager->getLocalConfigurationValueByPath('HTTP/proxy_port');
            $obsoleteParameters[] = 'HTTP/proxy_port';
        } catch (\RuntimeException $e) {
            $legacyProxyPort = '';
        }
        try {
            $legacyProxyUser = $this->configurationManager->getLocalConfigurationValueByPath('HTTP/proxy_user');
            $obsoleteParameters[] = 'HTTP/proxy_user';
        } catch (\RuntimeException $e) {
            $legacyProxyUser = '';
        }
        try {
            $legacyProxyPassword = $this->configurationManager->getLocalConfigurationValueByPath('HTTP/proxy_password');
            $obsoleteParameters[] = 'HTTP/proxy_password';
        } catch (\RuntimeException $e) {
            $legacyProxyPassword = '';
        }
        // Auth Scheme: Basic, digest etc.
        try {
            $legacyProxyAuthScheme = $this->configurationManager->getLocalConfigurationValueByPath('HTTP/proxy_auth_scheme');
            $obsoleteParameters[] = 'HTTP/proxy_auth_scheme';
        } catch (\RuntimeException $e) {
            $legacyProxyAuthScheme = '';
        }

        if ($legacyProxyHost !== '') {
            $proxy = 'http://';
            if ($legacyProxyAuthScheme !== '' && $legacyProxyUser !== '' && $legacyProxyPassword !== '') {
                $proxy .= $legacyProxyUser . ':' . $legacyProxyPassword . '@';
            }
            $proxy .= $legacyProxyHost;
            if ($legacyProxyPort !== '') {
                $proxy .= ':' . $legacyProxyPort;
            }
            $newParameters['HTTP/proxy'] = $proxy;
        }

        // Verify peers
        // see http://docs.guzzlephp.org/en/latest/request-options.html#verify
        try {
            $legacySslVerifyPeer = $this->configurationManager->getLocalConfigurationValueByPath('HTTP/ssl_verify_peer');
            $obsoleteParameters[] = 'HTTP/ssl_verify_peer';
        } catch (\RuntimeException $e) {
            $legacySslVerifyPeer = '';
        }

        // Directory holding multiple Certificate Authority files
        try {
            $legacySslCaPath = $this->configurationManager->getLocalConfigurationValueByPath('HTTP/ssl_capath');
            $obsoleteParameters[] = 'HTTP/ssl_capath';
        } catch (\RuntimeException $e) {
            $legacySslCaPath = '';
        }
        // Certificate Authority file to verify the peer with (use when ssl_verify_peer is TRUE)
        try {
            $legacySslCaFile = $this->configurationManager->getLocalConfigurationValueByPath('HTTP/ssl_cafile');
            $obsoleteParameters[] = 'HTTP/ssl_cafile';
        } catch (\RuntimeException $e) {
            $legacySslCaFile = '';
        }
        if ($legacySslVerifyPeer !== '') {
            if ($legacySslCaFile !== '' && $legacySslCaPath !== '') {
                $newParameters['HTTP/verify'] = $legacySslCaPath . $legacySslCaFile;
            } elseif ((bool)$legacySslVerifyPeer === false) {
                $newParameters['HTTP/verify'] = false;
            }
        }

        // SSL Key + Passphrase
        // Name of a file containing local certificate
        try {
            $legacySslLocalCert = $this->configurationManager->getLocalConfigurationValueByPath('HTTP/ssl_local_cert');
            $obsoleteParameters[] = 'HTTP/ssl_local_cert';
        } catch (\RuntimeException $e) {
            $legacySslLocalCert = '';
        }

        // Passphrase with which local certificate was encoded
        try {
            $legacySslPassphrase = $this->configurationManager->getLocalConfigurationValueByPath('HTTP/ssl_passphrase');
            $obsoleteParameters[] = 'HTTP/ssl_passphrase';
        } catch (\RuntimeException $e) {
            $legacySslPassphrase = '';
        }

        if ($legacySslLocalCert !== '') {
            if ($legacySslPassphrase !== '') {
                $newParameters['HTTP/ssl_key'] = [
                    $legacySslLocalCert,
                    $legacySslPassphrase
                ];
            } else {
                $newParameters['HTTP/ssl_key'] = $legacySslLocalCert;
            }
        }

        // Update the LocalConfiguration file if obsolete parameters or new parameters are set
        if (!empty($obsoleteParameters)) {
            $this->configurationManager->removeLocalConfigurationKeysByPath($obsoleteParameters);
            $changed = true;
        }
        if (!empty($newParameters)) {
            $this->configurationManager->setLocalConfigurationValuesByPathValuePairs($newParameters);
            $changed = true;
        }
        if ($changed) {
            $this->throwRedirectException();
        }
    }

    /**
     * Detail configuration of Image Magick settings must be cleared
     * if Image Magick handling is disabled.
     *
     * "Configuration presets" in install tool is not type safe, so value
     * comparisons here are not type safe too, to not trigger changes to
     * LocalConfiguration again.
     *
     * @return void
     */
    protected function disableImageMagickDetailSettingsIfImageMagickIsDisabled()
    {
        $changedValues = array();
        try {
            $currentImValue = $this->configurationManager->getLocalConfigurationValueByPath('GFX/processor_enabled');
        } catch (\RuntimeException $e) {
            $currentImValue = $this->configurationManager->getDefaultConfigurationValueByPath('GFX/processor_enabled');
        }

        try {
            $currentImPathValue = $this->configurationManager->getLocalConfigurationValueByPath('GFX/processor_path');
        } catch (\RuntimeException $e) {
            $currentImPathValue = $this->configurationManager->getDefaultConfigurationValueByPath('GFX/processor_path');
        }

        try {
            $currentImPathLzwValue = $this->configurationManager->getLocalConfigurationValueByPath('GFX/processor_path_lzw');
        } catch (\RuntimeException $e) {
            $currentImPathLzwValue = $this->configurationManager->getDefaultConfigurationValueByPath('GFX/processor_path_lzw');
        }

        try {
            $currentImageFileExtValue = $this->configurationManager->getLocalConfigurationValueByPath('GFX/imagefile_ext');
        } catch (\RuntimeException $e) {
            $currentImageFileExtValue = $this->configurationManager->getDefaultConfigurationValueByPath('GFX/imagefile_ext');
        }

        try {
            $currentThumbnailsValue = $this->configurationManager->getLocalConfigurationValueByPath('GFX/thumbnails');
        } catch (\RuntimeException $e) {
            $currentThumbnailsValue = $this->configurationManager->getDefaultConfigurationValueByPath('GFX/thumbnails');
        }

        if (!$currentImValue) {
            if ($currentImPathValue != '') {
                $changedValues['GFX/processor_path'] = '';
            }
            if ($currentImPathLzwValue != '') {
                $changedValues['GFX/processor_path_lzw'] = '';
            }
            if ($currentImageFileExtValue !== 'gif,jpg,jpeg,png') {
                $changedValues['GFX/imagefile_ext'] = 'gif,jpg,jpeg,png';
            }
            if ($currentThumbnailsValue != 0) {
                $changedValues['GFX/thumbnails'] = 0;
            }
        }
        if (!empty($changedValues)) {
            $this->configurationManager->setLocalConfigurationValuesByPathValuePairs($changedValues);
            $this->throwRedirectException();
        }
    }

    /**
     * Detail configuration of Image Magick and Graphics Magick settings
     * depending on main values.
     *
     * "Configuration presets" in install tool is not type safe, so value
     * comparisons here are not type safe too, to not trigger changes to
     * LocalConfiguration again.
     *
     * @return void
     */
    protected function setImageMagickDetailSettings()
    {
        $changedValues = array();
        try {
            $currentProcessorValue = $this->configurationManager->getLocalConfigurationValueByPath('GFX/processor');
        } catch (\RuntimeException $e) {
            $currentProcessorValue = $this->configurationManager->getDefaultConfigurationValueByPath('GFX/processor');
        }

        try {
            $currentProcessorMaskValue = $this->configurationManager->getLocalConfigurationValueByPath('GFX/processor_allowTemporaryMasksAsPng');
        } catch (\RuntimeException $e) {
            $currentProcessorMaskValue = $this->configurationManager->getDefaultConfigurationValueByPath('GFX/processor_allowTemporaryMasksAsPng');
        }

        try {
            $currentProcessorEffectsValue = $this->configurationManager->getLocalConfigurationValueByPath('GFX/processor_effects');
        } catch (\RuntimeException $e) {
            $currentProcessorEffectsValue = $this->configurationManager->getDefaultConfigurationValueByPath('GFX/processor_effects');
        }

        if ((string)$currentProcessorValue !== '') {
            if ($currentProcessorMaskValue != 0) {
                $changedValues['GFX/processor_allowTemporaryMasksAsPng'] = 0;
            }
            if ($currentProcessorValue === 'GraphicsMagick') {
                if ($currentProcessorEffectsValue != -1) {
                    $changedValues['GFX/processor_effects'] = -1;
                }
            }
        }
        if (!empty($changedValues)) {
            $this->configurationManager->setLocalConfigurationValuesByPathValuePairs($changedValues);
            $this->throwRedirectException();
        }
    }

    /**
     * Migrate the definition of the image processor from the configuration value
     * im_version_5 to the setting processor.
     *
     * @return void
     */
    protected function migrateImageProcessorSetting()
    {
        $changedSettings = array();
        $settingsToRename = array(
            'GFX/im' => 'GFX/processor_enabled',
            'GFX/im_version_5' => 'GFX/processor',
            'GFX/im_v5effects' => 'GFX/processor_effects',
            'GFX/im_path' => 'GFX/processor_path',
            'GFX/im_path_lzw' => 'GFX/processor_path_lzw',
            'GFX/im_mask_temp_ext_gif' => 'GFX/processor_allowTemporaryMasksAsPng',
            'GFX/im_noScaleUp' => 'GFX/processor_allowUpscaling',
            'GFX/im_noFramePrepended' => 'GFX/processor_allowFrameSelection',
            'GFX/im_stripProfileCommand' => 'GFX/processor_stripColorProfileCommand',
            'GFX/im_useStripProfileByDefault' => 'GFX/processor_stripColorProfileByDefault',
            'GFX/colorspace' => 'GFX/processor_colorspace',
        );

        foreach ($settingsToRename as $oldPath => $newPath) {
            try {
                $value = $this->configurationManager->getLocalConfigurationValueByPath($oldPath);
                $this->configurationManager->setLocalConfigurationValueByPath($newPath, $value);
                $changedSettings[$oldPath] = true;
            } catch (\RuntimeException $e) {
                // If an exception is thrown, the value is not set in LocalConfiguration
                $changedSettings[$oldPath] = false;
            }
        }

        if (!empty($changedSettings['GFX/im_version_5'])) {
            $currentProcessorValue = $this->configurationManager->getLocalConfigurationValueByPath('GFX/im_version_5');
            $newProcessorValue = $currentProcessorValue === 'gm' ? 'GraphicsMagick' : 'ImageMagick';
            $this->configurationManager->setLocalConfigurationValueByPath('GFX/processor', $newProcessorValue);
        }

        if (!empty($changedSettings['GFX/im_noScaleUp'])) {
            $currentProcessorValue = $this->configurationManager->getLocalConfigurationValueByPath('GFX/im_noScaleUp');
            $newProcessorValue = !$currentProcessorValue;
            $this->configurationManager->setLocalConfigurationValueByPath('GFX/processor_allowUpscaling', $newProcessorValue);
        }

        if (!empty($changedSettings['GFX/im_noFramePrepended'])) {
            $currentProcessorValue = $this->configurationManager->getLocalConfigurationValueByPath('GFX/im_noFramePrepended');
            $newProcessorValue = !$currentProcessorValue;
            $this->configurationManager->setLocalConfigurationValueByPath('GFX/processor_allowFrameSelection', $newProcessorValue);
        }

        if (!empty($changedSettings['GFX/im_mask_temp_ext_gif'])) {
            $currentProcessorValue = $this->configurationManager->getLocalConfigurationValueByPath('GFX/im_mask_temp_ext_gif');
            $newProcessorValue = !$currentProcessorValue;
            $this->configurationManager->setLocalConfigurationValueByPath('GFX/processor_allowTemporaryMasksAsPng', $newProcessorValue);
        }

        if (!empty(array_filter($changedSettings))) {
            $this->configurationManager->removeLocalConfigurationKeysByPath(array_keys($changedSettings));
            $this->throwRedirectException();
        }
    }

    /**
     * Throw exception after configuration change to trigger a redirect.
     *
     * @throws RedirectException
     */
    protected function throwRedirectException()
    {
        throw new RedirectException(
            'Configuration updated, reload needed',
            1379024938
        );
    }

    /**
     * Migrate the configuration value thumbnails_png to a boolean value.
     *
     * @return void
     */
    protected function migrateThumbnailsPngSetting()
    {
        $changedValues = array();
        try {
            $currentThumbnailsPngValue = $this->configurationManager->getLocalConfigurationValueByPath('GFX/thumbnails_png');
        } catch (\RuntimeException $e) {
            $currentThumbnailsPngValue = $this->configurationManager->getDefaultConfigurationValueByPath('GFX/thumbnails_png');
        }

        if (is_int($currentThumbnailsPngValue) && $currentThumbnailsPngValue > 0) {
            $changedValues['GFX/thumbnails_png'] = true;
        }
        if (!empty($changedValues)) {
            $this->configurationManager->setLocalConfigurationValuesByPathValuePairs($changedValues);
            $this->throwRedirectException();
        }
    }
}
