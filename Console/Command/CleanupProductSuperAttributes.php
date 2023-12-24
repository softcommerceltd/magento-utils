<?php
/**
 * Copyright © Soft Commerce Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace SoftCommerce\Utils\Console\Command;

use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;
use Magento\Framework\DB\Adapter\AdapterInterface;
use SoftCommerce\Core\Model\Utils\GetEntityMetadataInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @inheritDoc
 */
class CleanupProductSuperAttributes extends Command
{
    private const COMMAND_NAME = 'utils:cleanup:product_super_attribute';
    private const ENTITY_ID_ARGUMENT = 'i';

    /**
     * @var array
     */
    private array $dataInMemory = [];

    /**
     * @var AdapterInterface
     */
    private AdapterInterface $connection;

    /**
     * @var GetEntityMetadataInterface
     */
    private GetEntityMetadataInterface $getEntityMetadata;

    /**
     * @param GetEntityMetadataInterface $getEntityMetadata
     * @param ResourceConnection $resourceConnection
     * @param string|null $name
     */
    public function __construct(
        GetEntityMetadataInterface $getEntityMetadata,
        ResourceConnection $resourceConnection,
        ?string $name = null
    ) {
        $this->getEntityMetadata = $getEntityMetadata;
        $this->connection = $resourceConnection->getConnection();
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Cleanup unused product super attributes.')
            ->setDefinition([
                new InputOption(
                    self::ENTITY_ID_ARGUMENT,
                    '-i',
                    InputOption::VALUE_REQUIRED,
                    'Entity ID argument. Accepts comma-separated values.'
                )
            ]);
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($productIds = $input->getOption(self::ENTITY_ID_ARGUMENT)) {
            $productIds = explode(',', $productIds);
        } else {
            $productIds = $this->getProductIds();
        }

        $productIds = array_map('intval', $productIds);

        foreach ($productIds as $productId) {
            try {
                $result = $this->process($productId);
                if ($result) {
                    $output->writeln(
                        sprintf(
                            '<info>A total of <comment>%s</comment> product super attributes have been cleanup'
                            . ' for a product with ID: <comment>%s</comment>.</info>',
                            $result,
                            $productId
                        )
                    );
                } else {
                    $output->writeln(
                        sprintf(
                            '<comment>Nothing to cleanup for a product with ID: %s...</comment>',
                            $productId
                        )
                    );
                }
            } catch (\Exception $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
            }
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * @param int $productId
     * @return int
     */
    private function process(int $productId): int
    {
        $childIds = $this->getRelationChildIds($productId);
        if (!$childIds
            || !$superAttributes = $this->getProductSuperAttributes($productId)
        ) {
            return 0;
        }

        $deleteRequest = [];
        $result = 0;
        foreach ($superAttributes as $entityId => $attributeId) {
            if (!$this->isAttributeValueExist((int) $attributeId, $childIds)) {
                $deleteRequest[$attributeId] = $entityId;
            }
        }

        if ($deleteRequest) {
            $result = $this->connection->delete(
                $this->connection->getTableName('catalog_product_super_attribute'),
                ['product_super_attribute_id IN (?)' => array_values($deleteRequest)]
            );
        }

        return $result;
    }

    /**
     * @return array
     * @throws \Exception
     */
    private function getProductIds(): array
    {
        $select = $this->connection->select()
            ->from(
                $this->connection->getTableName('catalog_product_entity'),
                $this->getEntityMetadata->getLinkField())
            ->where('type_id = ?', Configurable::TYPE_CODE);

        return $this->connection->fetchCol($select);
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

    /**
     * @param int $productId
     * @return array
     */
    private function getProductSuperAttributes(int $productId): array
    {
        $select = $this->connection->select()
            ->from(
                $this->connection->getTableName('catalog_product_super_attribute'),
                [
                    'product_super_attribute_id',
                    'attribute_id'
                ]
            )
            ->where('product_id = ?', $productId);

        return $this->connection->fetchPairs($select);
    }

    /**
     * @param int $attributeId
     * @return string|null
     */
    private function getAttributeBackendType(int $attributeId): ?string
    {
        if (!isset($this->dataInMemory[$attributeId])) {
            $select = $this->connection->select()
                ->from($this->connection->getTableName('eav_attribute'), 'backend_type')
                ->where('attribute_id = ?', $attributeId);
            $this->dataInMemory[$attributeId] = $this->connection->fetchOne($select);
        }

        return $this->dataInMemory[$attributeId] ?? null;

    }

    /**
     * @param int $attributeId
     * @param array $productIds
     * @return bool
     */
    private function isAttributeValueExist(int $attributeId, array $productIds): bool
    {
        $backendType = $this->getAttributeBackendType($attributeId);

        $select = $this->connection->select()
            ->from($this->connection->getTableName('catalog_product_entity_' . $backendType), 'value_id')
            ->where('attribute_id = ?', $attributeId)
            ->where('entity_id IN (?)', $productIds)
            ->where('store_id = ?', 0);

        return (bool) $this->connection->fetchOne($select);
    }
}
