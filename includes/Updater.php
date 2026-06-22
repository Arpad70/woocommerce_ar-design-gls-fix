<?php

declare(strict_types=1);

namespace ArDesign\GlsFix;

use ArDesign\Shared\Updates\GitHubPluginUpdater as BaseGitHubPluginUpdater;

if (! defined('ABSPATH')) {
    exit;
}

require_once WP_PLUGIN_DIR . '/ar-design-shared-support/includes/updates/GitHubPluginUpdater.php';

final class ArDesignGlsFixUpdater extends BaseGitHubPluginUpdater
{
    public function __construct(string $repositoryFullName, string $pluginBasename, string $currentVersion)
    {
        parent::__construct(
            $repositoryFullName,
            $pluginBasename,
            $currentVersion,
            array(
                'plugin_slug' => 'ar-design-gls-fix',
                'plugin_name' => 'AR Design GLS Fix for WooCommerce',
                'text_domain' => 'ar-design-gls-fix',
                'description' => 'Samostatný GLS fix modul pre WooCommerce spravovaný AR Design.',
                'author_label' => 'AR Design',
                'user_agent_slug' => 'ar-design-gls-fix',
                'cache_key_prefix' => 'ar_design_gls_fix_release_data_',
                'preferred_zip_names' => array('ar-design-gls-fix.zip'),
                'preferred_zip_prefixes' => array('ar-design-gls-fix-'),
                'allow_any_zip_fallback' => false,
            )
        );
    }
}
