<?php
/**
 * Copyright © Soft Commerce Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace SoftCommerce\Utils\Console\Command;

use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\FileSystemException;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\CreditmemoSender;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Email\Sender\ShipmentSender;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @inheritDoc
 */
class SendTransactionalEmail extends Command
{
    private const COMMAND_NAME = 'utils:send:email';
    private const ENTITY_ID_OPT = 'id';
    private const EMAIL_TYPE_OPT = 'type';

    /**
     * @var CreditmemoRepositoryInterface
     */
    private CreditmemoRepositoryInterface $creditmemoRepository;

    /**
     * @var CreditmemoSender
     */
    private CreditmemoSender $creditmemoSender;

    /**
     * @var InvoiceRepositoryInterface
     */
    private InvoiceRepositoryInterface $invoiceRepository;

    /**
     * @var InvoiceSender
     */
    private InvoiceSender $invoiceSender;

    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var OrderSender
     */
    private OrderSender $orderSender;

    private ShipmentRepositoryInterface $shipmentRepository;

    /**
     * @var ShipmentSender
     */
    private ShipmentSender $shipmentSender;

    /**
     * @param CreditmemoRepositoryInterface $creditmemoRepository
     * @param CreditmemoSender $creditmemoSender
     * @param InvoiceRepositoryInterface $invoiceRepository
     * @param InvoiceSender $invoiceSender
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderSender $orderSender
     * @param ShipmentRepositoryInterface $shipmentRepository
     * @param ShipmentSender $shipmentSender
     * @param string|null $name
     */
    public function __construct(
        CreditmemoRepositoryInterface $creditmemoRepository,
        CreditmemoSender $creditmemoSender,
        InvoiceRepositoryInterface $invoiceRepository,
        InvoiceSender $invoiceSender,
        OrderRepositoryInterface $orderRepository,
        OrderSender $orderSender,
        ShipmentRepositoryInterface $shipmentRepository,
        ShipmentSender $shipmentSender,
        ?string $name = null
    ) {
        $this->creditmemoRepository = $creditmemoRepository;
        $this->creditmemoSender = $creditmemoSender;
        $this->invoiceRepository = $invoiceRepository;
        $this->invoiceSender = $invoiceSender;
        $this->orderRepository = $orderRepository;
        $this->orderSender = $orderSender;
        $this->shipmentRepository = $shipmentRepository;
        $this->shipmentSender = $shipmentSender;
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Send transactional email.')
            ->setDefinition([
                new InputOption(
                    self::EMAIL_TYPE_OPT,
                    '-t',
                    InputOption::VALUE_REQUIRED,
                    'Email type option.'
                ),
                new InputOption(
                    self::ENTITY_ID_OPT,
                    '-i',
                    InputOption::VALUE_REQUIRED,
                    'Entity ID option.'
                )
            ]);
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$emailType = $input->getOption(self::EMAIL_TYPE_OPT)) {
            $output->writeln('<error>Email type is required. Expected: order, invoice, shipment or creditmemo.</error>');
            return Cli::RETURN_FAILURE;
        }

        if (!$idFilter = $input->getOption(self::ENTITY_ID_OPT)) {
            $output->writeln('<error>Entity ID is required.</error>');
            return Cli::RETURN_FAILURE;
        }

        $idFilter = explode(',', $idFilter);

        try {
            $this->process($emailType, $idFilter, $output);
        } catch (FileSystemException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * @param string $typeId
     * @param array $entityIds
     * @param OutputInterface $output
     * @return void
     * @throws \Exception
     */
    private function process(string $typeId, array $entityIds, OutputInterface $output): void
    {
        list($repository, $service) = $this->getProcessType($typeId);

        foreach ($entityIds as $entityId) {
            try {
                $entity = $repository->get($entityId);
                $service->send($entity);
                $output->writeln(
                    sprintf(
                        '<info>Email has been sent for %s with ID: %s.</info>',
                        "<comment>$typeId</comment>",
                        "<comment>$entityId</comment>"
                    )
                );
            } catch (\Exception $e) {
                $output->writeln(
                    sprintf(
                        "<error>Could not send email for %s with ID: %s</error> <comment>{$e->getMessage()}</comment>",
                        "<comment>$typeId</comment>",
                        "<comment>$entityId</comment>"
                    )
                );
            }
        }
    }

    /**
     * @param string $typeId
     * @return array
     * @throws \Exception
     */
    private function getProcessType(string $typeId): array
    {
        switch ($typeId) {
            case 'order':
                $typeResult = [
                    $this->orderRepository,
                    $this->orderSender
                ];
                break;
            case 'invoice':
                $typeResult = [
                    $this->invoiceRepository,
                    $this->invoiceSender
                ];
                break;
            case 'shipment':
                $typeResult = [
                    $this->shipmentRepository,
                    $this->shipmentSender
                ];
                break;
            case 'creditmemo':
                $typeResult = [
                    $this->creditmemoRepository,
                    $this->creditmemoSender
                ];
                break;
            default:
                throw new \Exception(sprintf('Entity %s is not supported.', $typeId));
        }

        return $typeResult;
    }
}
