<?php declare(strict_types=1);

namespace WeArePlanetPayment\Core\Util\Payload;


use Psr\Container\ContainerInterface;
use Shopware\Core\{Checkout\Cart\Tax\Struct\CalculatedTaxCollection,
    Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity,
    Checkout\Customer\CustomerEntity,
    Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity,
    Checkout\Order\OrderEntity,
    Checkout\Payment\Cart\PaymentTransactionStruct,
    Framework\DataAbstractionLayer\Search\Criteria,
    System\SalesChannel\SalesChannelContext
};
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use WeArePlanet\Sdk\{Model\AddressCreate,
    Model\ChargeAttempt,
    Model\CreationEntityState,
    Model\CriteriaOperator,
    Model\EntityQuery,
    Model\EntityQueryFilter,
    Model\EntityQueryFilterType,
    Model\LineItemAttributeCreate,
    Model\LineItemCreate,
    Model\LineItemType,
    Model\TaxCreate,
    Model\TransactionCreate,
    Model\TransactionPending
};
use WeArePlanetPayment\Core\{Api\PaymentMethodConfiguration\Entity\PaymentMethodConfigurationEntity,
    Settings\Struct\Settings,
    Util\Exception\InvalidPayloadException,
    Util\LocaleCodeProvider,
    Util\Payload\CustomProducts\CustomProductsLineItems,
    Util\Payload\CustomProducts\CustomProductsLineItemTypes
};

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Tax\TaxEntity;

/**
 * Class TransactionPayload
 *
 * @package WeArePlanetPayment\Core\Util\Payload
 */
class TransactionPayload extends AbstractPayload
{

    use CustomProductsLineItems;

    public const ORDER_TRANSACTION_CUSTOM_FIELDS_WEAREPLANET_SPACE_ID = 'weareplanet_space_id';
    public const ORDER_TRANSACTION_CUSTOM_FIELDS_WEAREPLANET_TRANSACTION_ID = 'weareplanet_transaction_id';

    public const WEAREPLANET_METADATA_SALES_CHANNEL_ID = 'salesChannelId';
    public const WEAREPLANET_METADATA_ORDER_ID = 'orderId';
    public const WEAREPLANET_METADATA_ORDER_TRANSACTION_ID = 'orderTransactionId';
    public const WEAREPLANET_METADATA_CUSTOMER_NAME = 'customerName';


    /**
     * @var \Shopware\Core\System\SalesChannel\SalesChannelContext
     */
    protected $salesChannelContext;

    /**
     * @var \Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct
     */
    protected $transaction;

    /**
     * @var \WeArePlanetPayment\Core\Settings\Struct\Settings
     */
    protected $settings;

    /**
     * @var \Psr\Container\ContainerInterface
     */
    protected $container;

    /**
     * @var \WeArePlanetPayment\Core\Util\LocaleCodeProvider
     */
    private $localeCodeProvider;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    protected EntityRepository $orderTransactionRepository;

    protected OrderEntity $order;

    /**
     * TransactionPayload constructor.
     *
     * @param \Psr\Container\ContainerInterface $container
     * @param \WeArePlanetPayment\Core\Util\LocaleCodeProvider $localeCodeProvider
     * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
     * @param \WeArePlanetPayment\Core\Settings\Struct\Settings $settings
     * @param \Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct $transaction
     */
    public function __construct(
        ContainerInterface            $container,
        LocaleCodeProvider            $localeCodeProvider,
        SalesChannelContext           $salesChannelContext,
        Settings                      $settings,
        PaymentTransactionStruct $transaction
    )
    {
        $this->localeCodeProvider = $localeCodeProvider;
        $this->salesChannelContext = $salesChannelContext;
        $this->settings = $settings;
        $this->transaction = $transaction;
        $this->container = $container;
        $this->translator = $this->container->get('translator');
        $this->orderTransactionRepository = $this->container->get('order_transaction.repository');

        $criteria = (new Criteria());
        $criteria->addFilter(new EqualsFilter('id', $this->transaction->getOrderTransactionId()));

        $orders = $this->orderTransactionRepository->search($criteria, $this->salesChannelContext->getContext())->getEntities();
        $orderId = $orders->first()->getOrderId();

        $criteria = new Criteria([$orderId]);
        $criteria
            ->addAssociation('lineItems')
            ->addAssociation('orderCustomer')
            ->addAssociation('transactions')
            ->addAssociation('currency')
            ;

        $this->order = $this->container->get('order.repository')->search($criteria, $this->salesChannelContext->getContext())->getEntities()->first();
    }

    /**
     * Get Transaction Payload
     *
     * @return \WeArePlanet\Sdk\Model\TransactionPending
     * @throws \Exception
     */
    public function get(int $version): TransactionPending
    {
        $customerId = $this->order->getOrderCustomer()->getCustomerId();
        $criteria = new Criteria([$customerId]);
        $criteria->addAssociation('activeBillingAddress')
            ->addAssociation('activeShippingAddress')
            ->addAssociation('activeShippingAddress')
            ->addAssociation('defaultBillingAddress')
            ->addAssociation('defaultShippingAddress')
            ->addAssociation('salutation');
        $customer = $this->container->get('customer.repository')->search($criteria, $this->salesChannelContext->getContext())->getEntities()->first();

        $lineItems = $this->getLineItems();

        $billingAddress = $this->getAddressPayload($customer, $customer->getActiveBillingAddress());
        $shippingAddress = $this->getAddressPayload($customer, $customer->getActiveShippingAddress(), false);

        $customerId = null;
        $customerName = null;
        if ($customer->getGuest() === false) {
            $customerId = $customer->getCustomerNumber();
            $customerName = '';
            if ($customer->getGuest() === false) {
                $customerId = $customer->getCustomerNumber();
                $customerName = $customer->getSalutation()->getDisplayName() . ' ' . $customer->getFirstName() . ' ' . $customer->getLastName();
            }
        }

        $transactionData = [
            'currency' => $this->order->getCurrency()->getIsoCode(),
            'customer_email_address' => $customer->getEmail(),
            'customer_id' => $customerId,
            'language' => $this->localeCodeProvider->getLocaleCodeFromContext($this->salesChannelContext->getContext()) ?? null,
            'merchant_reference' => $this->fixLength($this->order->getOrderNumber(), 100),
            'meta_data' => [
                self::WEAREPLANET_METADATA_ORDER_ID => $this->order->getId(),
                self::WEAREPLANET_METADATA_ORDER_TRANSACTION_ID => $this->order->getTransactions()->first()->getId(),
                self::WEAREPLANET_METADATA_SALES_CHANNEL_ID => $this->salesChannelContext->getSalesChannel()->getId(),
                self::WEAREPLANET_METADATA_CUSTOMER_NAME => $customerName,
            ],
            'shipping_method' => $this->salesChannelContext->getShippingMethod()->getName() ? $this->fixLength($this->salesChannelContext->getShippingMethod()->getName(), 200) : null,
            'space_view_id' => $this->settings->getSpaceViewId() ?? null,
        ];

        // we have to manually check for these additional fields as they might not be active
        if (!empty($additionalAddress1 = $customer->getDefaultBillingAddress()->getAdditionalAddressLine1())) {
            $transactionData['meta_data']['additionalAddress1'] = $additionalAddress1;
        }

        if (!empty($additionalAddress2 = $customer->getDefaultBillingAddress()->getAdditionalAddressLine2())) {
            $transactionData['meta_data']['additionalAddress2'] = $additionalAddress2;
        }

        if (!empty($this->order->getCustomerComment())) {
            $transactionData['meta_data']['customer_comment'] = $this->order->getCustomerComment();
        }

        $vatIds = $customer->getVatIds();
        if (!empty($vatIds)) {
            $taxNumber = $vatIds[0];
            $transactionData['meta_data']['taxNumber'] = $taxNumber;
        }

        if (!empty($companyDepartment = $customer->getDefaultBillingAddress()->getDepartment())) {
            $transactionData['meta_data']['billingCompanyDepartment'] = $companyDepartment;
        }

        if (!empty($companyDepartment = $customer->getDefaultShippingAddress()->getDepartment())) {
            $transactionData['meta_data']['shippingCompanyDepartment'] = $companyDepartment;
        }

        $transactionPayload = (new TransactionPending())
            ->setId($_SESSION['transactionId'])
            ->setVersion($version)
            ->setBillingAddress($billingAddress)
            ->setCurrency($transactionData['currency'])
            ->setCustomerEmailAddress($transactionData['customer_email_address'])
            ->setCustomerId($transactionData['customer_id'])
            ->setLanguage($transactionData['language'])
            ->setLineItems($lineItems)
            ->setMerchantReference($transactionData['merchant_reference'])
            ->setMetaData($transactionData['meta_data'])
            ->setShippingAddress($shippingAddress)
            ->setShippingMethod($transactionData['shipping_method']);

        $paymentConfiguration = $this->getPaymentConfiguration($this->salesChannelContext->getPaymentMethod()->getId());

        $transactionPayload->setAllowedPaymentMethodConfigurations([$paymentConfiguration->getPaymentMethodConfigurationId()]);

        $successUrl = $this->transaction->getReturnUrl() . '&status=paid';
        $failedUrl = $this->getFailUrl($this->order->getId()) . '&status=fail';
        $transactionPayload->setSuccessUrl($successUrl)
            ->setFailedUrl($failedUrl);

        if (!$transactionPayload->valid()) {
            $this->logger->critical('Transaction payload invalid:', $transactionPayload->listInvalidProperties());
            throw new InvalidPayloadException('Transaction payload invalid:' . json_encode($transactionPayload->listInvalidProperties()));
        }

        return $transactionPayload;
    }

    /**
     * Get transaction line items
     *
     * @return \WeArePlanet\Sdk\Model\LineItemCreate[]
     * @throws \Exception
     */
    protected function getLineItems(): array
    {
        $lineItems = [];
        $items = $this->order->getLineItems() ?? [];

        foreach ($items as $shopLineItem) {
            if ($this->shouldSkipLineItem($shopLineItem)) {
                continue;
            }

            if ($this->isCustomProductOption($shopLineItem)) {
                $shopLineItem = $this->updateCustomProductOptionLabel($shopLineItem);
            }

            $lineItem = $this->createLineItem($shopLineItem);
            $this->validateLineItem($lineItem);

            $lineItems[] = $lineItem;
        }

        $this->processDiscounts($items, $lineItems);
        $this->sortLineItemsByName($lineItems);

        $this->addOptionalLineItems($lineItems);

        return $lineItems;
    }

    /**
     * Determine if a line item should be skipped.
     */
    protected function shouldSkipLineItem($shopLineItem): bool
    {
        return in_array($shopLineItem->getType(), [
          CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS,
          'promotion'
        ]);
    }

    /**
     * Check if the line item is a custom product option.
     */
    protected function isCustomProductOption($shopLineItem): bool
    {
        return $shopLineItem->getType() === CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS_OPTION;
    }

    /**
     * Update the label of a custom product option.
     */
    protected function updateCustomProductOptionLabel($shopLineItem)
    {
        $customProductOptionParentLabel = $this->getCustomProductOptionLabel($shopLineItem->getParentId());
        $shopLineItem->setLabel($customProductOptionParentLabel . ': ' . $shopLineItem->getLabel());
        return $shopLineItem;
    }

    /**
     * Validate the created line item.
     */
    protected function validateLineItem($lineItem): void
    {
        if (!$lineItem->valid()) {
            $this->logger->critical('LineItem payload invalid:', $lineItem->listInvalidProperties());
            throw new InvalidPayloadException('LineItem payload invalid: ' . json_encode($lineItem->listInvalidProperties()));
        }
    }

    /**
     * Process discounts from the order items and add them to the line items array.
     */
    protected function processDiscounts($items, array &$lineItems): void
    {
        $itemsArray = is_array($items) ? $items : iterator_to_array($items);
        $discounts = array_filter($itemsArray, function ($orderItem) {
            return $orderItem->getType() === 'promotion';
        });

        if ($discounts) {
            foreach ($discounts as $discount) {
                $this->addDiscountLineItem($discount, $lineItems);
            }
        }
    }

    /**
     * Add discount line item.
     */
    protected function addDiscountLineItem($discount, array &$lineItems): void
    {
        $calculatedPrice = $discount->getPrice();
        $discountName = $discount->getLabel() ?? 'Unnamed';
        $definition = $discount->getPriceDefinition();

        if ($this->order->getTaxStatus() === 'net' || $definition instanceof \Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition) {
            $calculatedTaxesCollection = $calculatedPrice->getCalculatedTaxes();
            foreach ($calculatedTaxesCollection as $calculatedTax) {
                $rate = $calculatedTax->getTaxRate();
                $amount = $this->calculateDiscountAmount($calculatedTax);

                $lineItems[] = $this->createDiscountLineItem($discountName, $amount, $rate);
            }
        } else {
            $taxRules = $calculatedPrice->getTaxRules();

            if ($taxRules && $taxRules->count() > 0) {
                foreach ($taxRules as $taxRule) {
                    $rate = $taxRule->getTaxRate();
                    $amount = $calculatedPrice->getTotalPrice();
                    $lineItems[] = $this->createDiscountLineItem($discountName, $amount, $rate);
                }
            } else {
                $rate = $this->getDefaultTaxRate();
                $amount = $calculatedPrice->getTotalPrice();
                $lineItems[] = $this->createDiscountLineItem($discountName, $amount, $rate);
            }
        }
    }

    /**
     * @param string $discountName
     * @param float $amount
     * @param float $rate
     * @return LineItemCreate
     */
    private function createDiscountLineItem(string $discountName, float $amount, float $rate): LineItemCreate
    {
        $lineItem = new LineItemCreate();

        $discountSkuName = 'sku-discount-' . $rate . '-' . $discountName;
        $discountTitle = sprintf('DISCOUNT: %s (%s%% tax)', $discountName, $rate);
        if ($this->order->getTaxStatus() === 'tax-free') {
            $discountSkuName = 'sku-discount-' . $discountName;
            $discountTitle = sprintf('DISCOUNT: %s', $discountName);
        }

        $lineItem->setAmountIncludingTax($amount)
            ->setName($discountTitle)
            ->setQuantity(1)
            ->setShippingRequired(false)
            ->setSku($discountSkuName, 200)
            ->setType(LineItemType::DISCOUNT)
            ->setUniqueId('coupon-' . $discountSkuName);

        $taxRate = new TaxCreate([
            'title' => 'Discount Tax: ' . $rate,
            'rate' => $rate,
        ]);

        if ($this->order->getTaxStatus() !== 'tax-free') {
            $lineItem->setTaxes([$taxRate]);
        }

        return $lineItem;
    }

    /**
     * @return float
     */
    private function getDefaultTaxRate(): float
    {
        /** @var SystemConfigService $systemConfigService */
        $systemConfigService = $this->container->get(SystemConfigService::class);
        $taxId = $systemConfigService->get('core.tax.defaultTaxRate');

        if (!$taxId || !is_string($taxId)) {
            return 21.0;
        }

        $criteria = new Criteria([$taxId]);
        /** @var TaxRepository $taxRepository */
        $taxRepository = $this->container->get('tax.repository');
        $tax = $taxRepository->search($criteria, Context::createDefaultContext())->get($taxId);

        return $tax instanceof TaxEntity ? $tax->getTaxRate() : 21.0;
    }

    /**
     * Calculate discount amount including tax if necessary.
     */
    protected function calculateDiscountAmount($calculatedTax): float
    {
        $amount = self::round($calculatedTax->getPrice());
        if ($this->order->getTaxStatus() === 'net') {
            $amount = self::round($amount + $calculatedTax->getTax());
        }
        return $amount;
    }

    /**
     * Sort line items by name.
     */
    protected function sortLineItemsByName(array &$lineItems): void
    {
        usort($lineItems, function ($lineItem1, $lineItem2) {
            return strcmp($lineItem1->getName(), $lineItem2->getName());
        });
    }

    /**
     * Add optional shipping and adjustment line items.
     */
    protected function addOptionalLineItems(array &$lineItems): void
    {
        if (count($this->order->getShippingCosts()->getCalculatedTaxes()) === 1) {
            if ($shippingLineItem = $this->getShippingLineItem()) {
                $lineItems[] = $shippingLineItem;
            }
        } else {
            if ($multipleShippingLineItems = $this->getMultipleShippingLineItems()) {
                $lineItems = array_merge($lineItems, $multipleShippingLineItems);
            }
        }

        if ($adjustmentLineItem = $this->getAdjustmentLineItem($lineItems)) {
            $lineItems[] = $adjustmentLineItem;
        }
    }

    /**
     * @param string $lineItemParentId
     * @return string
     */
    protected function getCustomProductOptionLabel(string $lineItemParentId): string
    {
        $label = '';
        foreach ($this->order->getLineItems() as $shopLineItem) {
            if ($shopLineItem->getParentId() === $lineItemParentId && $shopLineItem->getType() === CustomProductsLineItemTypes::LINE_ITEM_TYPE_PRODUCT) {
                $label = $shopLineItem->getLabel();
                break;
            }
        }

        return $label;
    }

    /**
     *
     * @return \WeArePlanet\Sdk\Model\LineItemCreate|null
     * @throws \Exception
     * @var \Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity $shopLineItem
     */
    protected function createLineItem(OrderLineItemEntity $shopLineItem): ?LineItemCreate
    {
        $uniqueId = $shopLineItem->getId();
        $sku = $shopLineItem->getProductId() ? $shopLineItem->getProductId() : $uniqueId;
        $payLoad = $shopLineItem->getPayload();
        if (!empty($payLoad) && !empty($payLoad['productNumber'])) {
            $sku = $payLoad['productNumber'];
        }
        $sku = $this->fixLength($sku, 200);

        $amount = $shopLineItem->getTotalPrice() ? self::round($shopLineItem->getTotalPrice()) : 0;

        //include Tax Excluded for Net Tax display customer group
        if ($this->order->getTaxStatus() === 'net') {
            $amount = self::round($amount + $shopLineItem->getPrice()->getCalculatedTaxes()->getAmount());
        }

        $lineItem = (new LineItemCreate())
            ->setName($this->fixLength($shopLineItem->getLabel(), 150))
            ->setUniqueId($uniqueId)
            ->setSku($sku)
            ->setQuantity($shopLineItem->getQuantity() ?? 1)
            ->setAmountIncludingTax($amount);


        if (!empty($shopLineItem->getType()) && $shopLineItem->getType() == CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS) {

            $productAttributes = $this->getCustomProductLineItemAttribute($shopLineItem);
            $taxes = $this->getCustomProductTaxes(
                $shopLineItem->getPrice()->getCalculatedTaxes(),
                $this->translator->trans('weareplanet.payload.taxes'),
                $amount
            );

        } else {
            $productAttributes = $this->getProductAttributes($shopLineItem);

            $taxes = $this->getTaxes(
                $shopLineItem->getPrice()->getCalculatedTaxes(),
                $this->translator->trans('weareplanet.payload.taxes')
            );
        }


        if (!empty($productAttributes)) {
            $lineItem->setAttributes($productAttributes);
        }

        if (!empty($taxes)) {
            if ($this->order->getTaxStatus() !== 'tax-free') {
                $lineItem->setTaxes($taxes);
            }
        }

        if ($shopLineItem->getTotalPrice() >= 0) {
            $lineItem->setType(LineItemType::PRODUCT);
        } else {
            $lineItem->setType(LineItemType::DISCOUNT);
        }

        return $lineItem;
    }

    /**
     * @param \Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection $calculatedTaxes
     * @param string $title
     *
     * @return array
     */
    protected function getTaxes(CalculatedTaxCollection $calculatedTaxes, string $title): array
    {
        $taxes = [];
        foreach ($calculatedTaxes as $calculatedTax) {

            $tax = (new TaxCreate())
                ->setRate($calculatedTax->getTaxRate())
                ->setTitle($this->fixLength($title . ' : ' . $calculatedTax->getTaxRate(), 40));

            if (!$tax->valid()) {
                $this->logger->critical('Tax payload invalid:', $tax->listInvalidProperties());
                throw new InvalidPayloadException('Tax payload invalid:' . json_encode($tax->listInvalidProperties()));
            }

            $taxes [] = $tax;
        }

        return $taxes;
    }

    /**
     * @param \Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity $shopLineItem
     *
     * @return array|null
     */
    protected function getProductAttributes(OrderLineItemEntity $shopLineItem): ?array
    {
        $productAttributes = [];
        $lineItemPayload = $shopLineItem->getPayload();

        if (is_array($lineItemPayload) && !empty($lineItemPayload['options'])) {
            foreach ($lineItemPayload['options'] as $option) {

                $label = $option['group'];
                $lineItemAttributeCreate = (new LineItemAttributeCreate())
                    ->setLabel($this->fixLength($label, 512))
                    ->setValue($this->fixLength((string)$option['option'], 512));

                if ($lineItemAttributeCreate->valid()) {
                    $key = $this->fixLength('option_' . md5($label), 40);
                    $productAttributes[$key] = $lineItemAttributeCreate;
                } else {
                    $this->logger->critical('LineItemAttributeCreate payload invalid:', $lineItemAttributeCreate->listInvalidProperties());
                    throw new InvalidPayloadException('LineItemAttributeCreate payload invalid:' . json_encode($lineItemAttributeCreate->listInvalidProperties()));
                }
            }
        }

        return empty($productAttributes) ? null : $productAttributes;
    }

    /**
     * @return \WeArePlanet\Sdk\Model\LineItemCreate|null
     */
    protected function getShippingLineItem(): ?LineItemCreate
    {
        try {

            $amount = $this->order->getShippingTotal();
            $amount = self::round($amount);

            if ($amount > 0) {

                $shippingName = $this->salesChannelContext->getShippingMethod()->getName() ?? $this->translator->trans('weareplanet.payload.shipping.name');
                $taxes = $this->getTaxes(
                    $this->order->getShippingCosts()->getCalculatedTaxes(),
                    $shippingName
                );
                if ($this->order->getTaxStatus() === 'net') {
                    $amount = self::round($amount + $this->order->getShippingCosts()->getCalculatedTaxes()->getAmount());
                }


                $lineItem = (new LineItemCreate())
                    ->setAmountIncludingTax($amount)
                    ->setName($this->fixLength($shippingName . ' ' . $this->translator->trans('weareplanet.payload.shipping.lineItem'), 150))
                    ->setQuantity($this->order->getShippingCosts()->getQuantity() ?? 1)
                    ->setSku($this->fixLength($shippingName . '-Shipping', 200))
                    /** @noinspection PhpParamsInspection */
                    ->setType(LineItemType::SHIPPING)
                    ->setUniqueId($this->fixLength($shippingName . '-Shipping', 200));

                if ($this->order->getTaxStatus() !== 'tax-free') {
                    $lineItem->setTaxes($taxes);
                }

                if (!$lineItem->valid()) {
                    $this->logger->critical('Shipping LineItem payload invalid:', $lineItem->listInvalidProperties());
                    throw new InvalidPayloadException('Shipping LineItem payload invalid:' . json_encode($lineItem->listInvalidProperties()));
                }

                return $lineItem;
            }

        } catch (\Exception $exception) {
            $this->logger->critical(__CLASS__ . ' : ' . __FUNCTION__ . ' : ' . $exception->getMessage());
        }
        return null;
    }

    /**
     * @return array
     */
    protected function getMultipleShippingLineItems(): array
    {
        try {
            if ($this->order->getShippingTotal() > 0) {
                $lineItems = [];
                $shippingName = $this->salesChannelContext->getShippingMethod()->getName() ?? $this->translator->trans('weareplanet.payload.shipping.name');

                $isFirst = true;

                foreach ($this->order->getShippingCosts()->getCalculatedTaxes() as $taxItem) {
                    $amount = self::round($taxItem->getPrice());
                    if ($this->order->getTaxStatus() === 'net') {
                        $amount = self::round($amount + $taxItem->getTax());
                    }
                    $taxRate = $taxItem->getTaxRate();
                    $tax = (new TaxCreate())
                      ->setRate($taxRate)
                      ->setTitle('Tax rate: '.$taxRate);

                    $name = $taxRate . '%-' . $shippingName;
                    $lineItem = (new LineItemCreate())
                      ->setAmountIncludingTax($amount)
                      ->setName($this->fixLength($name . ' ' . $this->translator->trans('weareplanet.payload.shipping.lineItem'), 150))
                      ->setQuantity($this->order->getShippingCosts()->getQuantity() ?? 1)
                      ->setSku($this->fixLength($name . '-Shipping', 200))
                      ->setType($isFirst ? LineItemType::SHIPPING : LineItemType::FEE) // First item as SHIPPING, rest as FEE
                      ->setUniqueId($this->fixLength($name . '-Shipping', 200));

                    if ($this->order->getTaxStatus() !== 'tax-free') {
                        $lineItem->setTaxes([$tax]);
                    }

                    if (!$lineItem->valid()) {
                        $this->logger->critical('Shipping LineItem payload invalid:', $lineItem->listInvalidProperties());
                        throw new InvalidPayloadException('Shipping LineItem payload invalid:' . json_encode($lineItem->listInvalidProperties()));
                    }

                    $lineItems[] = $lineItem;
                    $isFirst = false;
                }
                return $lineItems;
            }

        } catch (\Exception $exception) {
            $this->logger->critical(__CLASS__ . ' : ' . __FUNCTION__ . ' : ' . $exception->getMessage());
        }
        return [];
    }

    /**
     * Get Adjustment Line Item
     *
     * @param \WeArePlanet\Sdk\Model\LineItemCreate[] $lineItems
     *
     * @return \WeArePlanet\Sdk\Model\LineItemCreate|null
     * @throws \Exception
     */
    protected function getAdjustmentLineItem(array &$lineItems): ?LineItemCreate
    {
        $lineItem = null;

        $lineItemPriceTotal = array_sum(array_map(static function (LineItemCreate $lineItem) {
            return $lineItem->getAmountIncludingTax();
        }, $lineItems));

        $adjustmentPrice = $this->order->getAmountTotal() - $lineItemPriceTotal;
        $adjustmentPrice = self::round($adjustmentPrice);

        if (abs($adjustmentPrice) != 0) {
            if ($this->settings->isLineItemConsistencyEnabled()) {
                $error = strtr('LineItems total :lineItemTotal does not add up to order total :orderTotal', [
                    ':lineItemTotal' => $lineItemPriceTotal,
                    ':orderTotal' => $this->order->getAmountTotal(),
                ]);
                $this->logger->critical($error);
                throw new \Exception($error);

            } else {
                $lineItem = (new LineItemCreate())
                    ->setName($this->translator->trans('weareplanet.payload.adjustmentLineItem'))
                    ->setUniqueId('Adjustment-Line-Item')
                    ->setSku('Adjustment-Line-Item')
                    ->setQuantity(1);
                /** @noinspection PhpParamsInspection */
                $lineItem->setAmountIncludingTax($adjustmentPrice)
                    ->setType(($adjustmentPrice > 0) ? LineItemType::FEE : LineItemType::DISCOUNT);

                if (!$lineItem->valid()) {
                    $this->logger->critical('Adjustment LineItem payload invalid:', $lineItem->listInvalidProperties());
                    throw new InvalidPayloadException('Adjustment LineItem payload invalid:' . json_encode($lineItem->listInvalidProperties()));
                }
            }
        }

        return $lineItem;
    }

    /**
     * Get address payload
     *
     * @param \Shopware\Core\Checkout\Customer\CustomerEntity $customer
     * @param \Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity $customerAddressEntity
     *
     * @return \WeArePlanet\Sdk\Model\AddressCreate
     * @throws \Exception
     */
    protected function getAddressPayload(CustomerEntity $customer, CustomerAddressEntity $customerAddressEntity, bool $returnSalesTaxNumber = true): AddressCreate
    {
        // Family name
        $family_name = null;
        if (!empty($customerAddressEntity->getLastName())) {
            $family_name = $customerAddressEntity->getLastName();
        } else {
            if (!empty($customer->getLastName())) {
                $family_name = $customer->getLastName();
            }
        }
        $family_name = !empty($family_name) ? $this->fixLength($family_name, 100) : null;

        // Given name
        $given_name = null;
        if (!empty($customerAddressEntity->getFirstName())) {
            $given_name = $customerAddressEntity->getFirstName();
        } else {
            if (!empty($customer->getFirstName())) {
                $given_name = $customer->getFirstName();
            }
        }
        $given_name = !empty($given_name) ? $this->fixLength($given_name, 100) : null;

        // Organization name
        $organization_name = null;
        if (!empty($customerAddressEntity->getCompany())) {
            $organization_name = $customerAddressEntity->getCompany();
        }

        $organization_name = !empty($organization_name) ? $this->fixLength($organization_name, 100) : null;

        $salesTaxNumber = null;
        if ($returnSalesTaxNumber) {
            // salesTaxNumber
            $vatIds = $customer->getVatIds();
            if (!empty($vatIds)) {
                $salesTaxNumber = $vatIds[0];
            }
        }

        // Salutation
        $salutation = null;
        if (!(
            empty($customerAddressEntity->getSalutation()) ||
            empty($customerAddressEntity->getSalutation()->getDisplayName())
        )) {
            $salutation = $customerAddressEntity->getSalutation()->getDisplayName();
        } else {
            if (!empty($customer->getSalutation())) {
                $salutation = $customer->getSalutation()->getDisplayName();

            }
        }
        $salutation = !empty($salutation) ? $this->fixLength($salutation, 20) : null;

        $birthday = null;
        if (!empty($customer->getBirthday())) {
            $birthday = new \DateTime();
            $birthday->setTimestamp($customer->getBirthday()->getTimestamp());
            $birthday = $birthday->format('Y-m-d');
        }

        $postalState = $customerAddressEntity?->getCountryState()?->getName() ?? '';
        if (empty($postalState)) {
            $postalState = $customerAddressEntity?->getCountryState()?->getShortCode() ?? '';
        }

        $addressData = [
            'city' => $customerAddressEntity->getCity() ? $this->fixLength($customerAddressEntity->getCity(), 100) : null,
            'country' => $customerAddressEntity->getCountry() ? $customerAddressEntity->getCountry()->getIso() : null,
            'email_address' => $customer->getEmail() ? $this->fixLength($customer->getEmail(), 254) : null,
            'family_name' => $family_name,
            'given_name' => $given_name,
            'organization_name' => $organization_name,
            'phone_number' => $customerAddressEntity->getPhoneNumber() ? $this->fixLength($customerAddressEntity->getPhoneNumber(), 100) : null,
            'postcode' => $customerAddressEntity->getZipcode() ? $this->fixLength($customerAddressEntity->getZipcode(), 40) : null,
            'postal_state' => $postalState,
            'salutation' => $salutation,
            'street' => $customerAddressEntity->getStreet() ? $this->fixLength($customerAddressEntity->getStreet(), 300) : null,
            'birthday' => $birthday
        ];

        if ($returnSalesTaxNumber) {
            $addressData['sales_tax_number'] = $salesTaxNumber;
        }

        $addressPayload = (new AddressCreate())
            ->setCity($addressData['city'])
            ->setCountry($addressData['country'])
            ->setEmailAddress($addressData['email_address'])
            ->setFamilyName($addressData['family_name'])
            ->setGivenName($addressData['given_name'])
            ->setOrganizationName($addressData['organization_name'])
            ->setPhoneNumber($addressData['phone_number'])
            ->setPostCode($addressData['postcode'])
            ->setPostalState($addressData['postal_state'])
            ->setSalutation($addressData['salutation'])
            ->setStreet($addressData['street']);

        if ($returnSalesTaxNumber) {
            $addressPayload->setSalesTaxNumber($addressData['sales_tax_number']);
        }

        if (!empty($addressData['birthday'])) {
            $addressPayload->setDateOfBirth($addressData['birthday']);
        }

        if (!$addressPayload->valid()) {
            $this->logger->critical('Address payload invalid:', $addressPayload->listInvalidProperties());
            throw new InvalidPayloadException('Address payload invalid:' . json_encode($addressPayload->listInvalidProperties()));
        }

        return $addressPayload;
    }

    /**
     * @param string $id
     *
     * @return \WeArePlanetPayment\Core\Api\PaymentMethodConfiguration\Entity\PaymentMethodConfigurationEntity
     */
    protected function getPaymentConfiguration(string $id): PaymentMethodConfigurationEntity
    {
        $criteria = (new Criteria([$id]));

        return $this->container->get('weareplanet_payment_method_configuration.repository')
            ->search($criteria, $this->salesChannelContext->getContext())
            ->getEntities()->first();
    }

    /**
     * Get failure URL
     *
     * @param string $orderId
     *
     * @return string
     */
    protected function getFailUrl(string $orderId): string
    {
        return $this->container->get('router')->generate(
            'frontend.weareplanet.checkout.recreate-cart',
            ['orderId' => $orderId,],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }
}
