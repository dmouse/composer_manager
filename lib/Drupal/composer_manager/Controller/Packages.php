<?php

/**
 * @file
 * Contains \Drupal\composer_manager\Controller\Packages.
 */

namespace Drupal\composer_manager\Controller;

use Drupal\Component\Utility\String;
use Drupal\Component\Utility\Xss;
use Drupal\composer_manager\Form\RebuildForm;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\composer_manager\ComposerPackagesInterface;

/**
 * Provides a controller for display the list of composer packages.
 */
class Packages implements ContainerInjectionInterface {

  /**
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * @var \Drupal\composer_manager\ComposerPackagesInterface
   */
  protected $packages;

  /**
   * Constructs a \Drupal\composer_manager\Controller\Packages object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   * @param \Drupal\Core\Config\ConfigFactory $config_factory
   * @param \Drupal\composer_manager\ComposerPackagesInterface $packages
   */
  public function __construct(ModuleHandlerInterface $module_handler, ConfigFactory $config_factory, ComposerPackagesInterface $packages) {
    $this->moduleHandler = $module_handler;
    $this->configFactory = $config_factory;
    $this->packages = $packages;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('module_handler'),
      $container->get('config.factory'),
      $container->get('composer_manager.packages')
    );
  }

  /**
   * Shows the status of all packages required by contrib.
   *
   * @return array
   *   Returns a render array as expected by drupal_render().
   */
  public function page() {
    $build = array();
    $error = FALSE;

    $header = array(
      'package' => t('Package'),
      'version' => t('Installed Version'),
      'requirement' => t('Version Required by Module'),
    );

    try {
      $required = $this->packages->getRequired();
      $installed = $this->packages->getInstalled();
      $dependents = $this->packages->getDependencies();
      $combined = array_unique(array_merge(array_keys($required), array_keys($installed)));
    }
    catch (\RuntimeException $e) {
      $error = TRUE;
      drupal_set_message(Xss::filterAdmin($e->getMessage()));
      watchdog_exception('composer_manager', $e);
      $combined = array();
    }

    // Whether a Composer update is needed.
    $update_needed = FALSE;
    // Whether different modules require different versions of the same package.
    $has_conflicts = FALSE;

    $rows = array();
    foreach ($combined as $package_name) {
      $is_installed = isset($installed[$package_name]);

      // If the package is installed but has no dependents and is not required
      // by any modules, then the module that required it has most likely been
      // disabled and the package will be uninstalled on the next Composer
      // update.
      $not_required = $is_installed && !isset($dependents[$package_name]) && empty($required[$package_name]);

      // Get the package name and description.
      if ($is_installed && !empty($installed[$package_name]['homepage'])) {
        $options = array('attributes' => array('target' => '_blank'));
        $name = l($package_name, $installed[$package_name]['homepage'], $options);
      }
      else {
        $name = String::checkPlain($package_name);
      }
      if ($is_installed && !empty($installed[$package_name]['description'])) {
        $name .= '<div class="description">' . String::checkPlain($installed[$package_name]['description']) . '</div>';
      }

      // Get the version required by the module.
      $has_conflict = FALSE;
      if ($not_required) {
        $update_needed = TRUE;
        $requirement = t('No longer required');
        $requirement .= '<div class="description">' . t('Package will be removed on the next Composer update') . '</div>';
      }
      elseif (isset($required[$package_name])) {

        // Sets message based on whether there is a potential version conflict.
        $has_conflict = count($required[$package_name]) > 1;
        if ($has_conflict) {
          $has_conflicts = TRUE;
          $requirement = t('Potential version conflict');
        }
        else {
          $requirement = String::checkPlain(key($required[$package_name]));
        }

        // Build the list of modules that require this package.
        $modules = array();
        $requirement .= '<div class="description">';
        foreach ($required[$package_name] as $version => $module_names) {
          foreach ($module_names as $module_name) {
            $module_info = system_get_info('module', $module_name);
            $modules[] = String::checkPlain($module_info['name']);
          }
        }
        $requirement .= t('Required by: ') . join(', ', $modules);
        $requirement .= '</div>';
      }
      else {
        // This package is a dependency of a package directly required by a
        // module. Therefore we cannot detect the required version without using
        // the Composer tool which is expensive and too slow for the web.
        $requirement = t('N/A');
        $requirement .= '<div class="description">' . t('Dependency for other packages') . '</div>';
      }

      // Get the version that is installed.
      if ($is_installed) {
        $instaled_version = String::checkPlain($installed[$package_name]['version']);
      }
      else {
        $update_needed = TRUE;
        $instaled_version = t('Not installed');
      }

      // Set the row status.
      if (!$is_installed) {
        $class = array('error');
      }
      elseif ($has_conflict || $not_required) {
        $class = array('warning');
      }
      else {
        $class = array('ok');
      }

      $rows[$package_name] = array(
        'class' => $class,
        'data' => array(
          'package' => $name,
          'installed' => $instaled_version,
          'requirement' => $requirement,
        ),
      );
    }

    ksort($rows);
    $build['packages'] = array(
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#caption' => t('Status of Packages Managed by Composer'),
      '#attributes' => array(
        'class' => array('system-status-report'),
      ),
    );

    $rebuild_form = new RebuildForm($this->configFactory);
    $build['refresh_form'] = drupal_get_form($rebuild_form);

    // Get requirements so we can display them on the packages page.
    $this->moduleHandler->loadInclude('composer_manager', 'install');
    $requirements = composer_manager_requirements('runtime');

    if (REQUIREMENT_INFO != $requirements['composer_manager']['severity']) {
      drupal_set_message($requirements['composer_manager']['description'], 'error');
    }
    elseif ($update_needed) {
      $args = array('!command' => 'update', '@url' => url('http://drupal.org/project/composer_manager', array('absolute' => TRUE)));
      drupal_set_message(t('Packages need to be installed or removed by running Composer\'s <code>!command</code> command.<br/>Refer to the instructions on the <a href="@url" target="_blank">Composer Manager project page</a> for updating packages.', $args), 'warning');
    }
    if ($has_conflicts) {
      drupal_set_message(t('Potentially conflicting versions of the same package are required by different modules.'), 'warning');
    }

    return $build;
  }

}
