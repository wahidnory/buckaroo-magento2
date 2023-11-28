<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License
 * It is available through the world-wide-web at this URL:
 * https://tldrlegal.com/license/mit-license
 * If you are unable to obtain it through the world-wide-web, please send an email
 * to support@buckaroo.nl so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this module to newer
 * versions in the future. If you wish to customize this module for your
 * needs please contact support@buckaroo.nl for more information.
 *
 * @copyright Copyright (c) Buckaroo B.V.
 * @license   https://tldrlegal.com/license/mit-license
 */
namespace Buckaroo\Magento2\Plugin;

use Buckaroo\Magento2\Logging\Log;
use Buckaroo\Magento2\Model\ConfigProvider\Method\Factory;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\State\CommandInterface as MagentoCommandInterface;
use Buckaroo\Magento2\Helper\Data;
use Buckaroo\Magento2\Model\Method\PayPerEmail;

class CommandInterface
{
    const LOCK_PREFIX = 'buckaroo_lock_';

    /**
     * @var Log $logging
     */
    public $logging;

    /**
     * @var Factory
     */
    public $configProviderMethodFactory;

    /**
     * @var Data
     */
    public $helper;

    /**
     * @var LockManagerInterface
     */
    protected LockManagerInterface $lockManager;


    /**
     * @param Factory $configProviderMethodFactory
     * @param Log $logging
     * @param Data $helper
     * @param LockManagerInterface $lockManager
     */
    public function __construct(
        Factory $configProviderMethodFactory,
        Log $logging,
        Data $helper,
        LockManagerInterface $lockManager
    ) {
        $this->configProviderMethodFactory = $configProviderMethodFactory;
        $this->logging = $logging;
        $this->helper = $helper;
        $this->lockManager = $lockManager;
    }

    /**
     * @param MagentoCommandInterface $commandInterface
     * @param \Closure                $proceed
     * @param OrderPaymentInterface   $payment
     * @param                         $amount
     * @param OrderInterface          $order
     *
     * @return mixed
     */
    public function aroundExecute(
        MagentoCommandInterface $commandInterface,
        \Closure $proceed,
        OrderPaymentInterface $payment,
        $amount,
        OrderInterface $order
    ) {
        $message = $proceed($payment, $amount, $order);

        $lockName = $this->generateLockName($order);
        $this->logging->addDebug(__METHOD__ . '|Lock Name| - ' . var_export($lockName, true));

        $lockAcquired = $this->lockManager->lock($lockName, 2);

        if (!$lockAcquired) {
            $this->logging->addError(__METHOD__ . '|lock not acquired|');
            return $message;
        }

        try {
            /** @var MethodInterface $methodInstance */
            $methodInstance = $payment->getMethodInstance();
            $paymentAction = $methodInstance->getConfigPaymentAction();
            $paymentCode = substr($methodInstance->getCode(), 0, 18);

            $this->logging->addDebug(
                __METHOD__ . '|1|' . var_export([$methodInstance->getCode(), $paymentAction], true)
            );

            if ($paymentCode == 'buckaroo_magento2_' && $paymentAction) {
                if (($methodInstance->getCode() == 'buckaroo_magento2_payperemail') && ($paymentAction == 'order')) {
                    $config = $this->configProviderMethodFactory->get(PayPerEmail::PAYMENT_METHOD_CODE);
                    if ($config->getEnabledB2B()) {
                        $this->logging->addDebug(__METHOD__ . '|5|');
                        return $message;
                    }
                }
                $this->updateOrderStateAndStatus($order, $methodInstance);
            }

            return $message;

        } catch (\Exception $e) {
            $this->logging->addDebug(__METHOD__ . '|Exception|' . $e->getMessage());
            throw $e;
        } finally {
            // Ensure the lock is released
            $this->lockManager->unlock($lockName);
            $this->logging->addDebug(__METHOD__ . '|Lock released|');
        }

        return $this->_response;
    }

    /**
     * @param OrderInterface|Order $order
     * @param MethodInterface      $methodInstance
     */
    private function updateOrderStateAndStatus(OrderInterface $order, MethodInterface $methodInstance)
    {
        $orderState = Order::STATE_NEW;
        $orderStatus = $this->helper->getOrderStatusByState($order, $orderState);

        $this->logging->addDebug(__METHOD__ . '|5|' . var_export($orderStatus, true));

        if ((
                (
                    preg_match('/afterpay/', $methodInstance->getCode())
                    &&
                    $this->helper->getOriginalTransactionKey($order->getIncrementId())
                ) ||
                (
                    preg_match('/eps/', $methodInstance->getCode())
                    &&
                    ($this->helper->getMode($methodInstance->getCode()) != Data::MODE_LIVE)
                )
            )
            &&
            ($orderStatus == 'pending')
            &&
            ($order->getState() === Order::STATE_PROCESSING)
            &&
            ($order->getStatus() === Order::STATE_PROCESSING)
        ) {
            $this->logging->addDebug(__METHOD__ . '|10|');
            return false;
        }

        //skip setting the status here for applepay 
        if (preg_match('/applepay/', $methodInstance->getCode())) {
            return;
        }
        $order->setState($orderState);
        $order->setStatus($orderStatus);
    }

    /**
     * Generate a unique lock name for the push request.
     *
     * @param OrderInterface $order
     * @return string
     */
    protected function generateLockName(OrderInterface $order): string
    {
        return self::LOCK_PREFIX . sha1($order->getIncrementId());
    }
}
