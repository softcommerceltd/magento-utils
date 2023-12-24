<?php
/**
 * Copyright © Soft Commerce Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace SoftCommerce\Utils\Console\Command;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;
use Magento\Framework\DB\Adapter\AdapterInterface;
use SoftCommerce\Core\Model\Eav\GetEntityTypeIdInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @inheritDoc
 */
class AssignProductToCategoryByAttributeSet extends Command
{
    private const COMMAND_NAME = 'utils:assign:product_to_category_by_attribute_set';
    private const ATTRIBUTE_SET_ID_ARG = 'attribute_set_id';
    private const CATEGORY_ID_ARG = 'category_id';

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
            ->setDescription('Assigns products to a category by attribute set ID.')
            ->setDefinition([
                new InputOption(
                    self::ATTRIBUTE_SET_ID_ARG,
                    '-a',
                    InputOption::VALUE_REQUIRED,
                    'Attribute SET ID argument'
                ),
                new InputOption(
                    self::CATEGORY_ID_ARG,
                    '-c',
                    InputOption::VALUE_REQUIRED,
                    'Category ID argument'
                )
            ]);
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$attributeSetId = (int) $input->getOption(self::ATTRIBUTE_SET_ID_ARG)) {
            $output->writeln("<error>Please provide an attribute set ID.</error>");
            return Cli::RETURN_FAILURE;
        }

        if (!$categoryIds = $input->getOption(self::CATEGORY_ID_ARG)) {
            $output->writeln("<error>Please provide a target category ID.</error>");
            return Cli::RETURN_FAILURE;
        }

        $categoryIds = explode(',', $categoryIds);
        $categoryIds = array_map('intval', $categoryIds);

        $i = 0;
        foreach ($this->getProductIds($attributeSetId) as $productId) {
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
     * @param int $attributeSetId
     * @return array
     */
    private function getProductIds(int $attributeSetId): array
    {
        $select = $this->connection->select()
            ->from(
                ['cpe' => $this->connection->getTableName('catalog_product_entity')],
                'cpe.entity_id'
            )
            ->joinLeft(
                ['ea' => $this->connection->getTableName('eav_attribute')],
                'ea.attribute_code = \'visibility\'',
                null
            )
            ->joinLeft(
                ['cpei' => $this->connection->getTableName('catalog_product_entity_int')],
                'cpe.entity_id  = cpei.entity_id' .
                ' AND ea.attribute_id = cpei.attribute_id AND cpei.store_id = 0',
                null
            )
            ->where('attribute_set_id = ?', $attributeSetId)
            ->where(
                'cpei.value IN (?)',
                [
                    Visibility::VISIBILITY_BOTH,
                    Visibility::VISIBILITY_IN_CATALOG,
                    Visibility::VISIBILITY_IN_SEARCH
                ]
            )
            ->order('entity_id DESC');

        return array_map('intval', $this->connection->fetchCol($select));
    }
}
