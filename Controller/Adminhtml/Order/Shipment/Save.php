<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace DpdConnect\Shipping\Controller\Adminhtml\Order\Shipment;

use DpdConnect\Shipping\Helper\Constants;
use DpdConnect\Shipping\Helper\Data;
use DpdConnect\Shipping\Helper\DpdSettings;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Sales\Model\Order\Shipment\Validation\QuantityValidator;

/**
 * Class Save
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Save extends \Magento\Backend\App\Action implements HttpPostActionInterface
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'Magento_Sales::shipment';

    /**
     * @var \Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoader
     */
    protected $shipmentLoader;

    /**
     * @var \Magento\Shipping\Model\Shipping\LabelGenerator
     */
    protected $labelGenerator;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\ShipmentSender
     */
    protected $shipmentSender;

    /**
     * @var \Magento\Sales\Model\Order\Shipment\ShipmentValidatorInterface
     */
    private $shipmentValidator;
    /**
     * @var Data
     */
    private $dataHelper;
    /**
     * @var DpdSettings
     */
    private $dpdSettings;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @param Data $dataHelper
     * @param DpdSettings $dpdSettings
     * @param \Magento\Backend\App\Action\Context $context
     * @param \Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoader $shipmentLoader
     * @param \Magento\Shipping\Model\Shipping\LabelGenerator $labelGenerator
     * @param \Magento\Sales\Model\Order\Email\Sender\ShipmentSender $shipmentSender
     * @param \Magento\Sales\Model\Order\Shipment\ShipmentValidatorInterface|null $shipmentValidator
     */
    public function __construct(
        Data $dataHelper,
        DpdSettings $dpdSettings,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Backend\App\Action\Context $context,
        \Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoader $shipmentLoader,
        \Magento\Shipping\Model\Shipping\LabelGenerator $labelGenerator,
        \Magento\Sales\Model\Order\Email\Sender\ShipmentSender $shipmentSender,
        \Magento\Sales\Model\Order\Shipment\ShipmentValidatorInterface $shipmentValidator = null
    ) {
        parent::__construct($context);

        $this->shipmentLoader = $shipmentLoader;
        $this->labelGenerator = $labelGenerator;
        $this->shipmentSender = $shipmentSender;
        $this->shipmentValidator = $shipmentValidator ?: \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Magento\Sales\Model\Order\Shipment\ShipmentValidatorInterface::class);
        $this->dataHelper = $dataHelper;
        $this->dpdSettings = $dpdSettings;
        $this->orderRepository = $orderRepository;
    }

    /**
     * Save shipment and order in one transaction
     *
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     * @return $this
     */
    protected function _saveShipment($shipment)
    {
        $shipment->getOrder()->setIsInProcess(true);
        $transaction = $this->_objectManager->create(
            \Magento\Framework\DB\Transaction::class
        );
        $transaction->addObject(
            $shipment
        )->addObject(
            $shipment->getOrder()
        )->save();

        return $this;
    }

    /**
     * Save shipment
     *
     * We can save only new shipment. Existing shipments are not editable
     *
     * @return \Magento\Framework\Controller\ResultInterface
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function execute()
    {
        /** @var \Magento\Backend\Model\View\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();

        $formKeyIsValid = $this->_formKeyValidator->validate($this->getRequest());
        $isPost = $this->getRequest()->isPost();
        if (!$formKeyIsValid || !$isPost) {
            $this->messageManager->addErrorMessage(__('We can\'t save the shipment right now.'));
            return $resultRedirect->setPath('sales/order/index');
        }

        $data = $this->getRequest()->getParam('shipment');

        if (!empty($data['comment_text'])) {
            $this->_objectManager->get(\Magento\Backend\Model\Session::class)->setCommentText($data['comment_text']);
        }

        $isNeedCreateLabel = isset($data['create_shipping_label']) && $data['create_shipping_label'];
        $responseAjax = new \Magento\Framework\DataObject();

        try {
            if ($isNeedCreateLabel) {
                // Load the order
                $order = $this->orderRepository->get($this->getRequest()->getParam('order_id'));

                // Create rows from all packages
                $packages = $this->getRequest()->getParam('packages');
                $packageId = 1;
                $rows = [];

                foreach($packages as $package) {
                   $newPackage[$packageId] = $package;

                    $row = [
                        'code' => $package['params']['shipping_product'],
                        'productType' => $package['params']['product_type'],
                        'shipmentGeneralData' => $data,
                        'packageData' => $newPackage,
                    ];

                    if (isset($package['params']['expiration_date'])) {
                        $row['expirationDate'] = $package['params']['expiration_date'];
                    }

                    if (isset($package['params']['goods_description'])) {
                        $row['description'] = $package['params']['goods_description'];
                    }
                    $packageId = $packageId + 1;
                    $rows[] = $row;
                }

                $order->setData(Constants::ORDER_EXTRA_SHIPPING_DATA, $rows);
                $this->dataHelper->generateShippingLabel($order, null, $packages);

                $order->setCustomerNoteNotify(!empty($data['send_email']));

                $responseAjax->setOk(true);
            } else {
                $this->shipmentLoader->setOrderId($this->getRequest()->getParam('order_id'));
                $this->shipmentLoader->setShipmentId($this->getRequest()->getParam('shipment_id'));
                $this->shipmentLoader->setShipment($data);
                $this->shipmentLoader->setTracking($this->getRequest()->getParam('tracking'));
                $shipment = $this->shipmentLoader->load();
                if (!$shipment) {
                    return $this->resultFactory->create(ResultFactory::TYPE_FORWARD)->forward('noroute');
                }

                if (!empty($data['comment_text'])) {
                    $shipment->addComment(
                        $data['comment_text'],
                        isset($data['comment_customer_notify']),
                        isset($data['is_visible_on_front'])
                    );

                    $shipment->setCustomerNote($data['comment_text']);
                    $shipment->setCustomerNoteNotify(isset($data['comment_customer_notify']));
                }
                $validationResult = $this->shipmentValidator->validate($shipment, [QuantityValidator::class]);

                if ($validationResult->hasMessages()) {
                    $this->messageManager->addErrorMessage(
                        __("Shipment Document Validation Error(s):\n" . implode("\n", $validationResult->getMessages()))
                    );
                    return $resultRedirect->setPath('*/*/new', ['order_id' => $this->getRequest()->getParam('order_id')]);
                }
                $shipment->register();

                $shipment->getOrder()->setCustomerNoteNotify(!empty($data['send_email']));

                $this->_saveShipment($shipment);

                if (!empty($data['send_email'])) {
                    $this->shipmentSender->send($shipment);
                }
            }

            $shipmentCreatedMessage = __('The shipment has been created.');
            $labelCreatedMessage = __('You created the shipping label.');

            $this->messageManager->addSuccessMessage(
                $isNeedCreateLabel ? $shipmentCreatedMessage . ' ' . $labelCreatedMessage : $shipmentCreatedMessage
            );
            $this->_objectManager->get(\Magento\Backend\Model\Session::class)->getCommentText(true);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            if ($isNeedCreateLabel) {
                $responseAjax->setError(true);
                $responseAjax->setMessage($e->getMessage());
            } else {
                $this->messageManager->addErrorMessage($e->getMessage());
                return $resultRedirect->setPath('*/*/new', ['order_id' => $this->getRequest()->getParam('order_id')]);
            }
        } catch (\Exception $e) {
            $this->_objectManager->get(\Psr\Log\LoggerInterface::class)->critical($e);
            if ($isNeedCreateLabel) {
                $responseAjax->setError(true);
                $responseAjax->setMessage(__('An error occurred while creating shipping label.'));
            } else {
                $this->messageManager->addErrorMessage(__('Cannot save shipment.'));
                return $resultRedirect->setPath('*/*/new', ['order_id' => $this->getRequest()->getParam('order_id')]);
            }
        }
        if ($isNeedCreateLabel) {
            return $this->resultFactory->create(ResultFactory::TYPE_JSON)->setJsonData($responseAjax->toJson());
        }

        return $resultRedirect->setPath('sales/order/view', ['order_id' => $shipment->getOrderId()]);
    }
}
