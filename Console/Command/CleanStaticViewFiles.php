<?php
/**
 * Copyright © Soft Commerce Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace SoftCommerce\Utils\Console\Command;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @inheritDoc
 */
class CleanStaticViewFiles extends Command
{
    private const COMMAND_NAME = 'utils:static:clean';

    /**
     * @var Filesystem
     */
    private Filesystem $filesystem;

    /**
     * @param Filesystem $filesystem
     * @param string|null $name
     */
    public function __construct(
        Filesystem $filesystem,
        ?string $name = null
    ) {
        $this->filesystem = $filesystem;
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Clean static files from pub/static and var/view_preprocessed directories');
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cleanupStaticView();
        $this->cleanupViewProcessed();

        try {
            $this->cleanupStaticView();
            $this->cleanupViewProcessed();
            $output->writeln('<info>Static view files have been cleaned up.</info>');
        } catch (FileSystemException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * @return void
     * @throws FileSystemException
     */
    private function cleanupStaticView(): void
    {
        $fileSystem = $this->filesystem->getDirectoryWrite(DirectoryList::STATIC_VIEW);
        foreach (['adminhtml', 'frontend', 'deployed_version.txt'] as $path) {
            $fileSystem->delete(
                $fileSystem->getAbsolutePath($path)
            );
        }
    }

    /**
     * @return void
     * @throws FileSystemException
     */
    private function cleanupViewProcessed(): void
    {
        $fileSystem = $this->filesystem->getDirectoryWrite(DirectoryList::TMP_MATERIALIZATION_DIR);
        $fileSystem->delete();
    }
}
