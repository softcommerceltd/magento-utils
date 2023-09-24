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
class ReplaceStringInDatabase extends Command
{
    private const COMMAND_NAME = 'utils:replace:string';
    private const TABLE_FILTER = 'table';
    private const STRING_FILTER = 'string';
    private const STRING_SUBTRACT = 'subtract';

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
                ),
                new InputOption(
                    self::STRING_FILTER,
                    '-s',
                    InputOption::VALUE_REQUIRED,
                    'String filter separated by double column.'
                ),
                new InputOption(
                    self::STRING_SUBTRACT,
                    '-u',
                    InputOption::VALUE_REQUIRED,
                    'String subtract filter.'
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

        if (!$stringFilter = $input->getOption(self::STRING_FILTER)) {
            $output->writeln(
                '<error>String is required. Haystack and needle must be separated by three double columns.'
                    .' E.g.: find string:::replace string</error>'
            );
            return Cli::RETURN_FAILURE;
        }

        try {
            $result = $this->process($tableFilter, $stringFilter, (int) $input->getOption(self::STRING_FILTER));
            if ($result) {
                $output->writeln(
                    sprintf('<info>String has been replaced for %s records.</info>', $result)
                );
            } else {
                $output->writeln('<comment>Nothing to replace...</comment>');
            }
        } catch (FileSystemException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * @param string $tableFilter
     * @param string $stringFilter
     * @param int $subtract
     * @return int
     * @throws \Exception
     */
    private function process(string $tableFilter, string $stringFilter, int $subtract = 0): int
    {
        $tableFilter = explode(':', $tableFilter);
        $tableFilter = array_map('trim', $tableFilter);
        $table = (string) current($tableFilter);
        $column = (string) end($tableFilter);

        $stringFilter = explode(':::', $stringFilter);
        $stringFilter = array_map('trim', $stringFilter);
        $stringSearch = (string) ($stringFilter[0] ?? '');
        $stringReplace = (string) ($stringFilter[1] ?? '');

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
            if ($subtract) {
                $value = substr($value, 0, -$subtract);
            }

            $request[] = [
                $primaryColumnName => $item[$primaryColumnName],
                $column => $value
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
