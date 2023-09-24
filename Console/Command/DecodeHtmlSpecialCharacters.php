<?php
/**
 * Copyright © Soft Commerce Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace SoftCommerce\Utils\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\FileSystemException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @inheritDoc
 */
class DecodeHtmlSpecialCharacters extends Command
{
    private const COMMAND_NAME = 'utils:decode:special_character';
    private const TABLE_FILTER = 'table';

    /**
     * @var AdapterInterface
     */
    private AdapterInterface $connection;

    /**
     * @param ResourceConnection $resourceConnection
     * @param string|null $name
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        ?string $name = null
    ) {
        $this->connection = $resourceConnection->getConnection();
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Convert the predefined HTML entities to characters.')
            ->setDefinition([
                new InputOption(
                    self::TABLE_FILTER,
                    '-t',
                    InputOption::VALUE_REQUIRED,
                    'DB table/value filter.'
                )
            ]);
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbTable = explode(':', $input->getOption(self::TABLE_FILTER) ?: '');

        if (!$dbTable || count($dbTable) < 2) {
            $output->writeln(
                '<error>DB table is required. DB table must be followed by double column to indicate a column'
                . ' value, e.g. cms_block:content</error>'
            );
            return Cli::RETURN_FAILURE;
        }

        try {
            $result = $this->process((string) current($dbTable), (string) end($dbTable));
            if ($result) {
                $output->writeln(
                    sprintf('<info>%s records processed.</info>', $result)
                );
            } else {
                $output->writeln('<comment>Nothing to process...</comment>');
            }
        } catch (FileSystemException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * @param string $table
     * @param string $column
     * @return int
     * @throws \Exception
     */
    public function process(string $table, string $column): int
    {
        $tableName = $this->connection->getTableName($table);
        $primaryColumnName = null;
        foreach ($this->connection->describeTable($tableName) as $colItem) {
            if (isset($colItem['PRIMARY'], $colItem['COLUMN_NAME']) && $colItem['PRIMARY']) {
                $primaryColumnName = $colItem['COLUMN_NAME'];
            }
        }

        if (!$primaryColumnName) {
            throw new \Exception('Could not allocate primary column name.');
        }

        $select = $this->connection->select()
            ->from($tableName, [$primaryColumnName, $column]);

        $request = [];
        foreach ($this->connection->fetchAll($select) as $item) {
            if (!isset($item[$primaryColumnName], $item[$column])) {
                continue;
            }

            $request[] = [
                $primaryColumnName => $item[$primaryColumnName],
                $column => html_entity_decode($item[$column])
            ];
        }

        if ($request) {
            $this->connection->insertOnDuplicate(
                $tableName,
                $request,
                [$column]
            );
        }

        return count($request);
    }
}
