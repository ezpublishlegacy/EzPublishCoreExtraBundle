<?php

namespace Lolart\EzPublishCoreExtraBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

/**
 * This is the class that loads and manages your bundle configuration
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class EzPublishCoreExtraExtension extends Extension implements PrependExtensionInterface
{
    public function getAlias()
    {
        return 'ezpublish_core_extra';
    }

    /**
     * {@inheritDoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration( $configuration, $configs );

        $loader = new Loader\YamlFileLoader( $container, new FileLocator( __DIR__.'/../Resources/config' ) );
        $loader->load( 'services.yml' );
    }

    /**
     * Alters core bundle configuration to extract settings needed.
     * Will then "sanitize" configuration not to make core bundle complain about unknown settings.
     *
     * Warning: black magic inside...
     *
     * @param ContainerBuilder $container
     */
    public function prepend( ContainerBuilder $container )
    {
        // Yes, I know, this is black magic...
        // But there is no other way to get/set configs and in our case prepending won't be sufficient.
        // Anyway this will only happen at container compile time, so performance won't be impacted once container is compiled and dumped.
        $r = new \ReflectionObject( $container );
        $rConfigs = $r->getProperty( 'extensionConfigs' );
        $rConfigs->setAccessible( true );
        $configs = $rConfigs->getValue( $container );

        foreach ( $configs['ezpublish'] as &$config )
        {
            if ( !isset( $config['system'] ) )
                continue;

            foreach ( $config['system'] as $sa => &$saConfig )
            {
                foreach ( array( 'location_view', 'content_view', 'block_view' ) as $viewConfigType )
                {
                    if ( isset( $saConfig[$viewConfigType] ) )
                    {
                        // Duplicate untouched configuration.
                        $this->setExtraViewConfig(
                            $container,
                            $saConfig[$viewConfigType],
                            $sa,
                            $viewConfigType
                        );
                        $saConfig[$viewConfigType] = $this->sanitizeCoreViewConfig( $saConfig[$viewConfigType] );
                    }
                }
            }
        }

        $rConfigs->setValue( $container, $configs );
    }

    /**
     * Registers extra view config settings in ezpublish_extra namespace.
     *
     * @param ContainerBuilder $container
     * @param array $viewConfig The view configuration array (e.g. location_view config array).
     * @param string $siteaccess The SiteAccess we want to register the extra config for.
     * @param string $configType Which view config type we're working on. Can be "location_view", "content_view" or "block_view".
     *
     * @throws \InvalidArgumentException
     */
    private function setExtraViewConfig( ContainerBuilder $container, array $viewConfig, $siteaccess, $configType )
    {
        // Finds and manipulate "params" key for every view definition (aka override rule).
        foreach ( $viewConfig as &$viewDefinitions )
        {
            foreach ( $viewDefinitions as &$viewDefinition )
            {
                if ( isset( $viewDefinition['params'] ) )
                {
                    foreach ( $viewDefinition['params'] as &$param )
                    {
                        // Service directly passed (i.e. "@some_defined_service")
                        // Assuming it's a ParameterProviderInterface
                        if ( is_string( $param ) && strpos( $param, '@' ) === 0 )
                        {
                            $param = array( 'service' => substr( $param, 1 ) );
                        }
                        else if ( is_array( $param ) )
                        {
                            if ( isset( $v['service'] ) )
                            {
                                if ( !is_string( $v['service'] ) || $v['service'][0] !== '@' )
                                {
                                    throw new \InvalidArgumentException(
                                        "Configuration error: In $configType, an array param with 'service' key must be a service identifier prepended by a '@' (e.g. @my_service)"
                                    );
                                }

                                $v['service'] = substr( $v['service'], 1 );
                            }
                        }
                    }
                }
            }
        }

        $container->setParameter( "ezpublish_extra.$siteaccess.$configType", $viewConfig );
    }

    /**
     * Removes view settings unknown by EzPublishCoreBundle.
     *
     * @param array $viewConfig
     *
     * @return array
     */
    private function sanitizeCoreViewConfig( array $viewConfig )
    {
        foreach ( $viewConfig as $viewType => &$viewDefinitions )
        {
            foreach ( $viewDefinitions as $viewName => &$viewDefinition )
            {
                unset( $viewDefinition['params'] );
            }
        }

        return $viewConfig;
    }
}
