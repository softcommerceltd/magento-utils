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
use SoftCommerce\Core\Model\Utils\GetEntityTypeIdInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @inheritDoc
 */
class AssignProductToCategoryByCategoryId extends Command
{
    private const COMMAND_NAME = 'utils:assign:product_to_category_by_category';
    private const CATEGORY_ID_FILTER_ARG = 'category_id';
    private const TARGET_CATEGORY_ID_ARG = 'target_category_id';

    /**
     * @var AdapterInterface
     */
    private AdapterInterface $connection;

    /**
     * @var GetEntityTypeIdInterface
     */
    private GetEntityTypeIdInterface $getEntityTypeId;

    /**
     * @param GetEntityTypeIdInterface $getEntityTypeId
     * @param ResourceConnection $resourceConnection
     * @param string|null $name
     */
    public function __construct(
        GetEntityTypeIdInterface $getEntityTypeId,
        ResourceConnection $resourceConnection,
        string $name = null
    ) {
        $this->getEntityTypeId = $getEntityTypeId;
        $this->connection = $resourceConnection->getConnection();
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Assigns products to a category by category ID.')
            ->setDefinition([
                new InputOption(
                    self::CATEGORY_ID_FILTER_ARG,
                    '-c',
                    InputOption::VALUE_REQUIRED,
                    'Category ID filter argument'
                ),
                new InputOption(
                    self::TARGET_CATEGORY_ID_ARG,
                    '-t',
                    InputOption::VALUE_REQUIRED,
                    'Target category ID argument'
                )
            ]);
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$categoryIdFilter = (int) $input->getOption(self::CATEGORY_ID_FILTER_ARG)) {
            $output->writeln("<error>Please provide a category ID for filtering products.</error>");
            return Cli::RETURN_FAILURE;
        }

        if (!$categoryIds = $input->getOption(self::TARGET_CATEGORY_ID_ARG)) {
            $output->writeln("<error>Please provide a target category ID.</error>");
            return Cli::RETURN_FAILURE;
        }

        $categoryIds = explode(',', $categoryIds);
        $categoryIds = array_map('intval', $categoryIds);

        $i = 0;
        foreach ($this->getProductIds($categoryIdFilter) as $productId) {
            try {
                $result = $this->process($productId, $categoryIds, $i);
                if ($result) {
                    $output->writeln(
                        sprintf(
                            '<info>The product with ID: <comment>%s</comment> has been assigned'
                            . ' to categories with ID(s): <comment>%s</comment>.</info>',
                            $productId,
                            implode(',', $categoryIds)
                        )
                    );
                } else {
                    $output->writeln(
                        sprintf(
                            '<comment>Nothing to assigned to a product with ID: <info>%s</info>.</comment>',
                            $productId
                        )
                    );
                }
                $i++;
            } catch (\Exception $e) {
                $output->writeln("<error>{$e->getMessage()}</error>");
            }
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * @param int $productId
     * @param array $categoryIds
     * @param int|null $position
     * @return int
     */
    private function process(int $productId, array $categoryIds, ?int $position = 0): int
    {
        $result = 0;
        $request = [];
        foreach ($categoryIds as $categoryId) {
            $request[$categoryId] = [
                'category_id' => $categoryId,
                'product_id' => $productId,
                'position' => $position
            ];
        }

        if ($request) {
            $result = (int) $this->connection->insertOnDuplicate(
                $this->connection->getTableName('catalog_category_product'),
                $request
            );
        }

        return $result;
    }

    /**
     * @param int $categoryIdFilter
     * @return array
     */
    private function getProductIds(int $categoryIdFilter): array
    {
        $select = $this->connection->select()
            ->from(
                $this->connection->getTableName('catalog_category_product'),
                'product_id'
            )
            ->where('category_id = ?', $categoryIdFilter);

        return array_map('intval', $this->connection->fetchCol($select));
    }
}
