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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @inheritDoc
 */
class AssignAttributeSetByCategory extends Command
{
    private const COMMAND_NAME = 'utils:assign:attribute_set';
    private const ATTRIBUTE_SET_ID_ARG = 'attribute_set_id';
    private const CATEGORY_ID_ARG = 'category_id';

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
            ->setDescription('Assign attribute set by category IDs.')
            ->setDefinition([
                new InputOption(
                    self::ATTRIBUTE_SET_ID_ARG,
                    '-a',
                    InputOption::VALUE_REQUIRED,
                    'Attribute set ID argument.'
                ),
                new InputOption(
                    self::CATEGORY_ID_ARG,
                    '-c',
                    InputOption::VALUE_REQUIRED,
                    'Category ID(s) argument. Accepts comma-separated values.'
                )
            ]);
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$attributeSetId = $input->getOption(self::ATTRIBUTE_SET_ID_ARG)) {
            $output->writeln('<error>Specify attribute set ID.</error>');
            return Cli::RETURN_FAILURE;
        }

        if (!$categoryIds = $input->getOption(self::CATEGORY_ID_ARG)) {
            $output->writeln('<error>Specify category ID(s).</error>');
            return Cli::RETURN_FAILURE;
        }

        try {
            $result = $this->process((int) $attributeSetId, explode(',', $categoryIds));
            if ($result) {
                $output->writeln(
                    sprintf(
                        '<info>A total of <comment>%s</comment> products have been assigned'
                        . ' to the attribute set with ID: <comment>%s</comment>.</info>',
                        $result,
                        $attributeSetId
                    )
                );
            } else {
                $output->writeln(
                    sprintf(
                        '<comment>Nothing to assign to the attribute set with ID: %s...</comment>',
                        $attributeSetId
                    )
                );
            }
        } catch (\Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * @param int $attributeSetId
     * @param array $categoryIds
     * @return int
     */
    private function process(int $attributeSetId, array $categoryIds): int
    {
        $result = 0;
        $request = [];
        foreach ($this->getProductIds($categoryIds) as $productId) {
            $request[$productId] = [
                'entity_id' => $productId,
                'attribute_set_id' => $attributeSetId
            ];
        }

        if ($request) {
            $result = $this->connection->insertOnDuplicate(
                $this->connection->getTableName('catalog_product_entity'),
                $request,
                ['attribute_set_id']
            );
        }

        return $result;
    }

    /**
     * @param array $categoryIds
     * @return array
     */
    private function getProductIds(array $categoryIds): array
    {
        $select = $this->connection->select()
            ->from($this->connection->getTableName('catalog_category_product'), 'product_id')
            ->where('category_id IN (?)', $categoryIds);

        $productIds = array_map(
            'intval',
            $this->connection->fetchCol($select)
        );

        $childIds = [];
        foreach ($productIds as $productId) {
            if ($relatedIds = $this->getRelationChildIds($productId)) {
                $childIds = array_merge($childIds, $relatedIds);
            }
        }

        return array_merge($productIds, $childIds);
    }

    /**
     * @param int $productId
     * @return array
     */
    private function getRelationChildIds(int $productId): array
    {
        $select = $this->connection->select()
            ->from($this->connection->getTableName('catalog_product_relation'), 'child_id')
            ->where('parent_id = ?', $productId);

        return $this->connection->fetchCol($select);
    }
}
