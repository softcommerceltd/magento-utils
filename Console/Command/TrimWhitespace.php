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
use Magento\Framework\Model\ResourceModel\IteratorFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @inheritDoc
 */
class TrimWhitespace extends Command
{
    private const COMMAND_NAME = 'utils:trim:whitespace';
    private const TABLE_NAME_FILTER = 'table';
    private const COLUMN_FILTER = 'column';

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
        string $name = null
    ) {
        $this->connection = $resourceConnection->getConnection();
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription(
                'Trim whitespace from a given database table column values.' .
                'Example: bin/magento utils:trim:whitespace -t catalog_product_entity -c sku'
            )
            ->setDefinition([
                new InputOption(
                    self::TABLE_NAME_FILTER,
                    '-t',
                    InputOption::VALUE_REQUIRED,
                    'Database table name.'
                ),
                new InputOption(
                    self::COLUMN_FILTER,
                    '-c',
                    InputOption::VALUE_REQUIRED,
                    'Database table column.'
                )
            ]);
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$tableName = $input->getOption(self::TABLE_NAME_FILTER)) {
            $output->writeln('<error>Specify database table name.</error>');
            return Cli::RETURN_FAILURE;
        }

        if (!$columnFilter = $input->getOption(self::COLUMN_FILTER)) {
            $output->writeln('<error>Specify database table column.</error>');
            return Cli::RETURN_FAILURE;
        }

        $schema = $this->connection->describeTable(
            $this->connection->getTableName($tableName)
        );
        $schema = array_filter($schema, function ($item) {
            return isset($item['PRIMARY']) && !!$item['PRIMARY'];
        });

        if (!$identifierFieldName = key($schema)) {
            $output->writeln('<error>Could not allocate primary column name.</error>');
            return Cli::RETURN_FAILURE;
        }

        if ($identifierFieldName === $columnFilter) {
            $output->writeln(
                sprintf(
                    '<error>%s is a primary column and cannot be used to strip whitespace.</error>',
                    $columnFilter
                )
            );
            return Cli::RETURN_FAILURE;
        }

        $select = $this->connection->select()
            ->from($this->connection->getTableName($tableName), [$identifierFieldName, $columnFilter]);

        foreach (array_chunk($this->connection->fetchAll($select), 20) as $batch) {
            foreach ($batch as & $item) {
                $entityId = $item[$identifierFieldName] ?? null;
                if (!$entityId || !$value = $item[$columnFilter] ?? null) {
                    continue;
                }

                $item[$columnFilter] = trim($value);
            }

            try {
                $this->connection->insertOnDuplicate(
                    $this->connection->getTableName($tableName),
                    $batch,
                    [$columnFilter]
                );
                $output->writeln(
                    sprintf(
                        '<info>Whitespaces have been stripped. </info><comment>Effected IDs: %s</comment>',
                        implode(',', array_column($batch, $identifierFieldName))
                    )
                );
            } catch (\Exception $e) {
                $output->writeln("<error>{$e->getMessage()}</error>");
            }
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * @param array $entity
     * @param array $store
     * @return int
     */
    protected function process(array $entity, array $store = []): int
    {
        $condition = ['entity_type IN (?)' => $entity];
        if ($store) {
            $condition['store_id IN (?)'] = $store;
        }

        return (int) $this->connection->delete(
            $this->connection->getTableName('url_rewrite'),
            $condition
        );
    }
}
