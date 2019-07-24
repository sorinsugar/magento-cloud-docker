<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Mcd\Command\Generate;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @inheritdoc
 */
class Php extends Command
{
    private const SUPPORTED_VERSIONS = ['7.0', '7.1', '7.2'];
    private const EDITION_CLI = 'cli';
    private const EDITION_FPM = 'fpm';
    private const EDITIONS = [self::EDITION_CLI, self::EDITION_FPM];
    private const ARGUMENT_VERSION = 'version';
    private const DEFAULT_PACKAGES_PHP_FPM = [
        'apt-utils',
        'sendmail-bin',
        'sendmail',
        'sudo'
    ];
    private const DEFAULT_PACKAGES_PHP_CLI = [
        'apt-utils',
        'sendmail-bin',
        'sendmail',
        'sudo',
        'cron',
        'mariadb-client',
        'git',
        'redis-tools',
        'nano',
        'unzip',
        'vim',
        'python3',
        'python3-pip',
    ];

    private const PHP_EXTENSIONS_ENABLED_BY_DEFAULT = [
        'bcmath',
        'bz2',
        'calendar',
        'exif',
        'gd',
        'gettext',
        'intl',
        'mysqli',
        'mcrypt',
        'pcntl',
        'pdo_mysql',
        'soap',
        'sockets',
        'sysvmsg',
        'sysvsem',
        'sysvshm',
        'redis',
        'opcache',
        'xsl',
        'zip',
    ];

    const DOCKERFILE = 'Dockerfile';
    const EXTENSION_OS_DEPENDENCIES = 'extension_os_dependencies';
    const EXTENSION_PACKAGE_NAME = 'extension_package_name';
    const EXTENSION_TYPE = 'extension_type';
    const EXTENSION_TYPE_PECL = 'extension_type_pecl';
    const EXTENSION_TYPE_CORE = 'extension_type_core';
    const EXTENSION_TYPE_INSTALLATION_SCRIPT = 'extension_type_installation_script';
    const EXTENSION_CONFIGURE_OPTIONS = 'extension_configure_options';
    const EXTENSION_INSTALLATION_SCRIPT = 'extension_installation_script';

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var VersionParser
     */
    private $versionParser;

    /**
     * @inheritdoc
     */
    public function __construct(?string $name = null)
    {
        $this->filesystem = new Filesystem();
        $this->versionParser = new VersionParser();

        parent::__construct($name);
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName('generate:php')
            ->setAliases(['g:php'])
            ->setDescription('Generates proper configs')
            ->addArgument(
                self::ARGUMENT_VERSION,
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'Generates PHP configuration',
                self::SUPPORTED_VERSIONS
            );

        parent::configure();
    }

    /**
     * {@inheritdoc}
     *
     * @throws FileNotFoundException
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $versions = $input->getArgument(self::ARGUMENT_VERSION);

        if ($diff = array_diff($versions, self::SUPPORTED_VERSIONS)) {
            throw new \InvalidArgumentException(sprintf(
                'Not supported versions %s',
                implode(' ', $diff)
            ));
        }

        foreach ($versions as $version) {
            foreach (self::EDITIONS as $edition) {
                $this->build($version, $edition, false);
            }
        }

        foreach ($versions as $version) {
            foreach (self::EDITIONS as $edition) {
                $this->build($version, $edition, true);
            }
        }

        $output->writeln('<info>Done</info>');
    }

    /**
     * @param string $version
     * @param string $edition
     * @param bool $dev
     * @throws FileNotFoundException
     */
    private function build(string $version, string $edition, bool $dev): void
    {
        $destination = BP . '/php/' . $version . '-' . $edition . ($dev ? '-dev' : '');
        $dataDir = DATA . '/php-' . $edition;
        $dockerfile = $destination . '/' . self::DOCKERFILE;

        $this->filesystem->deleteDirectory($destination);
        $this->filesystem->makeDirectory($destination);
        $this->filesystem->copyDirectory($dataDir, $destination);

        $this->filesystem->put($dockerfile, $this->buildDockerfile($dockerfile, $version, $edition, $dev));
    }

    /**
     * @param string $dockerfile
     * @param string $phpVersion
     * @param string $edition
     * @param bool $dev
     * @return string
     * @throws FileNotFoundException|\RuntimeException
     */
    private function buildDockerfile(string $dockerfile, string $phpVersion, string $edition, bool $dev): string
    {
        $phpConstraintObject = new Constraint('==', $this->versionParser->normalize($phpVersion));
        $phpExtConfigs = $this->filesystem->getRequire(DATA . '/php-extensions.php');

        $packages = self::EDITION_CLI == $edition ? self::DEFAULT_PACKAGES_PHP_CLI : self::DEFAULT_PACKAGES_PHP_FPM;
        $phpExtCore = [];
        $phpExtCoreConfigOptions = [];
        $phpExtList = [];
        $phpExtPecl = [];
        $phpExtInstScripts = [];
        $phpExtEnabledDefault = [];

        foreach ($phpExtConfigs as $phpExtName => $phpExtConfig) {
            if (!is_string($phpExtName)) {
                throw new \RuntimeException('Extension name not set');
            }
            foreach ($phpExtConfig as $phpExtConstraint => $phpExtInstallConfig) {
                $phpExtConstraintObject = $this->versionParser->parseConstraints($phpExtConstraint);
                if (!$phpConstraintObject->matches($phpExtConstraintObject)) {
                    continue;
                }
                $phpExtType = $phpExtInstallConfig[self::EXTENSION_TYPE];
                switch ($phpExtType) {
                    case self::EXTENSION_TYPE_CORE:
                        $phpExtCore[] = $phpExtInstallConfig[self::EXTENSION_PACKAGE_NAME] ?? $phpExtName;
                        if (isset($phpExtInstallConfig[self::EXTENSION_CONFIGURE_OPTIONS])) {
                            $phpExtCoreConfigOptions[] = sprintf(
                                "RUN docker-php-ext-configure \\\n  %s %s",
                                $phpExtName,
                                implode(' ', $phpExtInstallConfig[self::EXTENSION_CONFIGURE_OPTIONS])
                            );
                        }
                        break;
                    case self::EXTENSION_TYPE_PECL:
                        $phpExtPecl[] = $phpExtInstallConfig[self::EXTENSION_PACKAGE_NAME] ?? $phpExtName;
                        break;
                    case self::EXTENSION_TYPE_INSTALLATION_SCRIPT:
                        $phpExtInstScripts[] = implode(" \\\n", array_map(function (string $command) {
                            return strpos($command, 'RUN') === false ? '  && ' . $command : $command;
                        }, explode("\n", 'RUN ' . $phpExtInstallConfig[self::EXTENSION_INSTALLATION_SCRIPT])));
                        break;
                    default:
                        throw new \RuntimeException(sprintf(
                            'PHP extension %s. The type %s not supported',
                            $phpExtName,
                            $phpExtType
                        ));
                }
                if (
                    isset($phpExtInstallConfig[self::EXTENSION_OS_DEPENDENCIES])
                    && $phpExtType != self::EXTENSION_TYPE_INSTALLATION_SCRIPT
                ) {
                    $packages = array_merge($packages, $phpExtInstallConfig[self::EXTENSION_OS_DEPENDENCIES]);
                }
                if (in_array($phpExtName, self::PHP_EXTENSIONS_ENABLED_BY_DEFAULT)) {
                    $phpExtEnabledDefault[] = $phpExtName;
                }
                $phpExtList[] = $phpExtName;
            }
        }

        $volumes = [
            'root' => [
                'def' => 'VOLUME ${MAGENTO_ROOT}',
                'cmd' => 'RUN mkdir ${MAGENTO_ROOT} && chown www:www ${MAGENTO_ROOT}'
            ]
        ];

        if (!$dev) {
            $volumes = array_merge($volumes, [
                'vendor' => [
                    'def' => 'VOLUME ${MAGENTO_ROOT}/vendor',
                    'cmd' => 'RUN mkdir ${MAGENTO_ROOT}/vendor && chown www:www ${MAGENTO_ROOT}/vendor'
                ],
                'generated' => [
                    'def' => 'VOLUME ${MAGENTO_ROOT}/generated',
                    'cmd' => 'RUN mkdir ${MAGENTO_ROOT}/generated && chown www:www ${MAGENTO_ROOT}/generated'
                ],
                'var' => [
                    'def' => 'VOLUME ${MAGENTO_ROOT}/var',
                    'cmd' => 'RUN mkdir ${MAGENTO_ROOT}/var && chown www:www ${MAGENTO_ROOT}/var'
                ],
                'setup' => [
                    'def' => 'VOLUME ${MAGENTO_ROOT}/setup',
                    'cmd' => 'RUN mkdir ${MAGENTO_ROOT}/setup && chown www:www ${MAGENTO_ROOT}/setup'
                ],
                'etc' => [
                    'def' => 'VOLUME ${MAGENTO_ROOT}/app/etc',
                    'cmd' => 'RUN mkdir -p ${MAGENTO_ROOT}/app/etc && chown www:www ${MAGENTO_ROOT}/app/etc'
                ],
                'pub-static' => [
                    'def' => 'VOLUME ${MAGENTO_ROOT}/pub/static',
                    'cmd' => 'RUN mkdir -p ${MAGENTO_ROOT}/pub/static && chown www:www ${MAGENTO_ROOT}/pub/static'
                ],
                'pub-media' => [
                    'def' => 'VOLUME ${MAGENTO_ROOT}/pub/media',
                    'cmd' => 'RUN mkdir -p ${MAGENTO_ROOT}/pub/media && chown www:www ${MAGENTO_ROOT}/pub/media'
                ]
            ]);
        }

        $volumesCmd = '';
        $volumesDef = '';

        foreach ($volumes as $data) {
            $volumesCmd .= $data['cmd'] . "\n";
            $volumesDef .= $data['def'] . "\n";
        }

        return strtr(
            $this->filesystem->get($dockerfile),
            [
                '{%note%}' => '# This file is automatically generated. Do not edit directly. #',
                '{%version%}' => $phpVersion,
                '{%packages%}' => implode(" \\\n  ", array_unique($packages)),
                '{%docker-php-ext-configure%}' => implode(PHP_EOL, $phpExtCoreConfigOptions),
                '{%docker-php-ext-install%}' => !empty($phpExtCore)
                    ? "RUN docker-php-ext-install -j$(nproc) \\\n  " . implode(" \\\n  ", $phpExtCore)
                    : '',
                '{%php-pecl-extensions%}' => !empty($phpExtPecl)
                    ? "RUN pecl install -o -f \\\n  " . implode(" \\\n  ", $phpExtPecl)
                    : '',
                '{%docker-php-ext-enable%}' => !empty($phpExtList)
                    ? "RUN docker-php-ext-enable \\\n  " . implode(" \\\n  ", $phpExtList)
                    : '',
                '{%installation_scripts%}' => !empty($phpExtInstScripts)
                    ? implode(PHP_EOL, $phpExtInstScripts)
                    : '',
                '{%env_php_extensions%}' => !(empty($phpExtEnabledDefault))
                    ? 'ENV PHP_EXTENSIONS ' . implode(' ', $phpExtEnabledDefault)
                    : '',
                '{%volumes_cmd%}' => rtrim($volumesCmd),
                '{%volumes_def%}' => rtrim($volumesDef)
            ]
        );
    }
}
