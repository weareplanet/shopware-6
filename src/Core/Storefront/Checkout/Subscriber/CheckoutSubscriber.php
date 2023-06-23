<?php declare(strict_types=1);

namespace WeArePlanetPayment\Core\Storefront\Checkout\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates,
	Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection,
	Checkout\Order\OrderEntity,
	Content\MailTemplate\Service\Event\MailBeforeValidateEvent};
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use WeArePlanetPayment\Core\{
	Api\Transaction\Service\OrderMailService,
	Checkout\PaymentHandler\WeArePlanetPaymentHandler,
	Settings\Service\SettingsService,
	Util\PaymentMethodUtil};

/**
 * Class CheckoutSubscriber
 *
 * @package WeArePlanetPayment\Storefront\Checkout\Subscriber
 */
class CheckoutSubscriber implements EventSubscriberInterface {

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	protected $logger;

	/**
	 * @var \WeArePlanetPayment\Core\Util\PaymentMethodUtil
	 */
	private $paymentMethodUtil;

	/**
	 * @var \WeArePlanetPayment\Core\Settings\Service\SettingsService
	 */
	private $settingsService;

	/**
	 * CheckoutSubscriber constructor.
	 *
	 * @param \WeArePlanetPayment\Core\Settings\Service\SettingsService $settingsService
	 * @param \WeArePlanetPayment\Core\Util\PaymentMethodUtil           $paymentMethodUtil
	 */
	public function __construct(SettingsService $settingsService, PaymentMethodUtil $paymentMethodUtil)
	{
		$this->settingsService   = $settingsService;
		$this->paymentMethodUtil = $paymentMethodUtil;
	}

	/**
	 * @param \Psr\Log\LoggerInterface $logger
	 *
	 * @internal
	 * @required
	 *
	 */
	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}

	/**
	 * @return array
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			CheckoutConfirmPageLoadedEvent::class => ['onConfirmPageLoaded', 1],
			MailBeforeValidateEvent::class        => ['onMailBeforeValidate', 1],
		];
	}

	/**
	 * Stop order emails being sent out
	 *
	 * @param \Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent $event
	 */
	public function onMailBeforeValidate(MailBeforeValidateEvent $event): void
	{
		$templateData = $event->getTemplateData();
		
		/**
		 * @var $order \Shopware\Core\Checkout\Order\OrderEntity
		 */
		$order = !empty($templateData['order']) && $templateData['order'] instanceof OrderEntity ? $templateData['order'] : null;

		if (!empty($order) && $order->getAmountTotal() > 0){

			$isWeArePlanetEmailSettingEnabled = $this->settingsService->getSettings($order->getSalesChannelId())->isEmailEnabled();

			if (!$isWeArePlanetEmailSettingEnabled) { //setting is disabled
				return;
			}

			$orderTransactions = $order->getTransactions();
			if (!($orderTransactions instanceof OrderTransactionCollection)) {
				return;
			}
			$orderTransactionLast = $orderTransactions->last();
			if (empty($orderTransactionLast) || empty($orderTransactionLast->getPaymentMethod())) { // no payment method available
				return;
			}

			$isWeArePlanetPM = WeArePlanetPaymentHandler::class == $orderTransactionLast->getPaymentMethod()->getHandlerIdentifier();
			if (!$isWeArePlanetPM) { // not our payment method
				return;
			}

			$isOrderTransactionStateOpen = in_array(
				$orderTransactionLast->getStateMachineState()->getTechnicalName(), [
				OrderTransactionStates::STATE_OPEN,
				OrderTransactionStates::STATE_IN_PROGRESS,
			]);

			if (!$isOrderTransactionStateOpen) { // order payment status is open or in progress
				return;
			}

			$isWeArePlanetEmail = isset($templateData[OrderMailService::EMAIL_ORIGIN_IS_WEAREPLANET]);

			if (!$isWeArePlanetEmail) {
				$this->logger->info('Email disabled for ', ['orderId' => $order->getId()]);
				$event->stopPropagation();
			}
		}
	}

	/**
	 * @param \Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent $event
	 */
	public function onConfirmPageLoaded(CheckoutConfirmPageLoadedEvent $event): void
	{
		try {
			$settings = $this->settingsService->getValidSettings($event->getSalesChannelContext()->getSalesChannel()->getId());
			if (is_null($settings)) {
				$this->logger->notice('Removing payment methods because settings are invalid');
				$this->removeWeArePlanetPaymentMethodFromConfirmPage($event);
			}

		} catch (\Exception $e) {
			$this->logger->error($e->getMessage());
			$this->removeWeArePlanetPaymentMethodFromConfirmPage($event);
		}
	}

	/**
	 * @param \Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent $event
	 */
	private function removeWeArePlanetPaymentMethodFromConfirmPage(CheckoutConfirmPageLoadedEvent $event): void
	{
		$paymentMethodCollection = $event->getPage()->getPaymentMethods();
		$paymentMethodIds        = $this->paymentMethodUtil->getWeArePlanetPaymentMethodIds($event->getContext());
		foreach ($paymentMethodIds as $paymentMethodId) {
			$paymentMethodCollection->remove($paymentMethodId);
		}
	}
}