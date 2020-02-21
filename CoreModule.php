<?php
declare(strict_types=1);

namespace ArrayIterator\Service\Module\CoreModule;

use Apatis\Config\Config;
use Apatis\Config\ConfigInterface;
use ArrayIterator\Service\Bundle\Entity\Options;
use ArrayIterator\Service\Core\Module\AbstractModule;
use ArrayIterator\Service\Core\Module\AddOn;
use ArrayIterator\Service\Core\Module\Extension;
use ArrayIterator\Service\Core\Module\Loader;
use ArrayIterator\Service\Core\Traits\Helper\PathSanitation;
use DateTime;
use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * Class CoreModule
 * @package ArrayIterator\Service\Module\CoreModule
 */
class CoreModule extends AbstractModule
{
    use PathSanitation;

    protected $extensionHasLoaded = false;

    /**
     * {@inheritDoc}
     */
    protected function afterModulesInit()
    {
        // load Controller
        $this->addControllerByPath(__DIR__ .'/../../Controller');
        $this->loadExtension();
    }

    /**
     * @param Loader $loader
     * @return array|Options|mixed
     */
    protected function loadEmbedModuleStorage(Loader $loader)
    {
        $identifier = $loader->getIdentifier();
        try {
            $extensionsActiveList = [];
            $em = $this->getContainer()->get(EntityManagerInterface::class);
            $objectRepositoryOptions = $em->getRepository(Options::class);
            if ($objectRepositoryOptions instanceof ObjectRepository) {
                /**
                 * @var Options $options
                 */
                $options = $objectRepositoryOptions->findBy([
                    'identifier' => sprintf('%s.active', $identifier)
                ]);
                $options = $options->getProperties();
                $extensionsActiveList = $options;
                if (!is_array($options)) {
                    try {
                        $options->setProperties($extensionsActiveList);
                        $em->persist($options);
                        $em->flush();
                    } catch (Throwable $e) {
                        // pass
                    }
                }

                $oldExtensionActiveList = $extensionsActiveList;
                $newExtensions = [];
                foreach ($extensionsActiveList as $key => $v) {
                    if (!is_string($key)
                        || $loader->normalizeModuleName($key) === ''
                        || !is_string($v)
                        || !preg_match(
                            '~^[a-z_]([a-z0-9_]+)?$~i',
                            $loader->normalizeModuleName($key)
                        )
                    ) {
                        continue;
                    }

                    if (! @strtotime($v)) {
                        unset($extensionsActiveList[$key]);
                        try {
                            $date = new DateTime();
                            $date = $date->format('Y-m-d H:i:s');
                        } catch (Throwable $e) {
                            $date = date('Y-m-d H:i:s');
                        }
                        $newExtensions[$key] = $date;
                    }
                }
                $extensionsActiveList = array_merge(
                    $extensionsActiveList,
                    $newExtensions
                );
                $newExtensions = [];
                foreach ($extensionsActiveList as $key => $v) {
                    $key = $loader->normalizeModuleName($key);
                    if (isset($newExtensions[$key])) {
                        continue;
                    }
                    $newExtensions[$key] = $v;
                }
                $extensionsActiveList = $newExtensions;
                unset($newExtensions);
                if ($oldExtensionActiveList !== $extensionsActiveList) {
                    try {
                        $options->setProperties($extensionsActiveList);
                        $em->persist($options);
                        $em->flush();
                    } catch (Throwable $e) {
                        // pass
                    }
                }
                unset($oldExtensionActiveList);
            }

            unset($em, $config, $objectRepositoryOptions, $options);
            foreach ($loader->loadAll(false) as $key => $module) {
                if (isset($extensionsActiveList[$key])) {
                    $module->moduleInit();
                    continue;
                }
                unset($extensionsActiveList[$key]);
            }

            return $extensionsActiveList;
        } catch (Throwable $e) {
            // empty result
            return [];
        }
    }

    /**
     * Load Extensions
     */
    protected function loadExtension()
    {
        if ($this->extensionHasLoaded === true) {
            return;
        }
        $this->extensionHasLoaded = true;

        // ----------------------------
        // LOAD PREDEFINED EXTENSIONS |
        // ----------------------------
        $config = $this->container->get(ConfigInterface::class);
        $extension = $this->container->get(Extension::class);
        $addOns = $this->container->get(AddOn::class);

        // extensions
        $extensionsList = $config->get('extensions');
        if (!$extensionsList instanceof ConfigInterface) {
            $extensionsList = new Config();
        }
        $extensionsList = $extensionsList->toArray();
        $extensionsList = array_flip($extensionsList);
        $extensionIsDisable = ($config['disable']['database.extensions']??null);
        $extensionsActiveList = [];

        // addons
        $addOnsList = $config->get('addons');
        if (!$addOnsList instanceof ConfigInterface) {
            $addOnsList = new Config();
        }
        $addOnsList = $addOnsList->toArray();
        $addOnsList = array_flip($addOnsList);
        $addOnsIsDisable = ($config['disable']['database.addons']??null);
        $addOnsActiveList = [];

        // set request
        $extension->setRequest($this->getRequest());
        $addOns->setRequest($this->getRequest());

        unset($em, $config);
        foreach ($extension->loadAll(false) as $key => $module) {
            if (isset($extensionsList[$key])) {
                $module->moduleInit();
                continue;
            }
            unset($extensionsList[$key]);
        }

        foreach ($addOns->loadAll(false) as $key => $module) {
            if (isset($addOnsList[$key])) {
                $module->moduleInit();
                continue;
            }
            unset($addOnsList[$key]);
        }

        if ($extensionIsDisable !== true
            && !in_array($extensionIsDisable, ['yes', 'true', 1, '1'])
        ) {
            $extensionsActiveList = (array) $this->loadEmbedModuleStorage($extension);
        }

        if ($addOnsIsDisable !== true
            && !in_array($extensionIsDisable, ['yes', 'true', 1, '1'])
        ) {
            $addOnsActiveList = (array) $this->loadEmbedModuleStorage($addOns);
        }

        $extensionsList = array_merge($extensionsList, $extensionsActiveList);
        $addOnsList = array_merge($addOnsList, $addOnsActiveList);

        // load AfterInit
        foreach ($extensionsList as $key => $value) {
            unset($extensionsList[$key]);
            if (!$extension->exist($key)) {
                continue;
            }
            $extension->load($key)->moduleAfterInit();
        }

        unset($extension);

        // load add-on AfterInit
        foreach ($addOnsList as $key => $value) {
            unset($addOnsList[$key]);
            if (!$addOns->exist($key)) {
                continue;
            }
            $addOns->load($key)->moduleAfterInit();
        }

        unset(
            $addOns,
            $extensionsList,
            $extensionsActiveList,
            $addOnsList
        );

        // ----------------------------
        // END LOAD EXTENSIONS        |
        // ----------------------------
    }
}
