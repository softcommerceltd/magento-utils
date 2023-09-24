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
class FixStoreUrlParameters extends Command
{
    private const COMMAND_NAME = 'utils:fix:store_url_param';
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
            ->setDescription('Replace string in database table.')
            ->setDefinition([
                new InputOption(
                    self::TABLE_FILTER,
                    '-t',
                    InputOption::VALUE_REQUIRED,
                    'DB table filter.'
                )
            ]);
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$tableFilter = $input->getOption(self::TABLE_FILTER)) {
            $output->writeln(
                '<error>DB table is required. DB table must be followed by double column to indicate a column'
                . ' value, e.g. cms_block:content</error>'
            );
            return Cli::RETURN_FAILURE;
        }

        try {
            $result = $this->process($tableFilter);
            if ($result) {
                $output->writeln('<info>%s records have been processed.</info>', $result);
            } else {
                $output->writeln('<comment>Nothing to replace...</comment>');
            }
        } catch (FileSystemException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        return Cli::RETURN_SUCCESS;
    }

    public function test($tableFilter)
    {
        $result = $this->process($tableFilter);
    }

    /**
     * @param string $tableFilter
     * @param string $stringFilter
     * @return int
     * @throws \Exception
     */
    public function process(string $tableFilter): int
    {
        $tableFilter = explode(':', $tableFilter);
        $tableFilter = array_map('trim', $tableFilter);
        $table = (string) current($tableFilter);
        $column = (string) end($tableFilter);

        $stringSearch = '{{store url=';
        $stringReplace = '{{store direct_url=';

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
            ->from($tableName, [$primaryColumnName, $column])
            ->where($column . ' LIKE ?', "%$stringSearch%");

        $request = [];
        foreach ($this->connection->fetchAll($select) as $item) {
            if (!isset($item[$primaryColumnName], $item[$column])) {
                continue;
            }

            $value = $item[$column];
            $value = str_replace($stringSearch, $stringReplace, $value);
            $request[] = [
                $primaryColumnName => $item[$primaryColumnName],
                $column => html_entity_decode($value)
            ];
        }

        $result = 0;
        if ($request) {
            $result = $this->connection->insertOnDuplicate(
                $tableName,
                $request,
                [$column]
            );
        }

        return $result;
    }
}
