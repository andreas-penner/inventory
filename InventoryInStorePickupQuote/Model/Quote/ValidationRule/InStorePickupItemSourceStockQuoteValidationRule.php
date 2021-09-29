<?php
declare(strict_types=1);

namespace Magento\InventoryInStorePickupQuote\Model\Quote\ValidationRule;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Validation\ValidationResult;
use Magento\Framework\Validation\ValidationResultFactory;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryCatalog\Model\GetSourceItemsBySkuAndSourceCodes;
use Magento\InventoryInStorePickupApi\Api\Data\PickupLocationInterface;
use Magento\InventoryInStorePickupApi\Model\GetPickupLocationInterface;
use Magento\InventoryInStorePickupQuote\Model\GetWebsiteCodeByStoreId;
use Magento\InventoryInStorePickupQuote\Model\IsPickupLocationShippingAddress;
use Magento\InventoryInStorePickupShippingApi\Model\IsInStorePickupDeliveryCartInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ValidationRules\QuoteValidationRuleInterface;

/**
 * Validate Quote for In-Store Pickup Delivery Method.
 */
class InStorePickupItemSourceStockQuoteValidationRule implements QuoteValidationRuleInterface
{
    /**
     * @var ValidationResultFactory
     */
    protected ValidationResultFactory $validationResultFactory;

    /**
     * @var IsPickupLocationShippingAddress
     */
    protected IsPickupLocationShippingAddress $isPickupLocationShippingAddress;

    /**
     * @var GetPickupLocationInterface
     */
    protected GetPickupLocationInterface $getPickupLocation;

    /**
     * @var GetWebsiteCodeByStoreId
     */
    protected GetWebsiteCodeByStoreId $getWebsiteCodeByStoreId;

    /**
     * @var IsInStorePickupDeliveryCartInterface
     */
    protected IsInStorePickupDeliveryCartInterface $isInStorePickupDeliveryCart;

    /**
     * @var GetSourceItemsBySkuAndSourceCodes
     */
    protected GetSourceItemsBySkuAndSourceCodes $sourceItemsBySkuAndSourceCodes;

    /**
     * @param ValidationResultFactory $validationResultFactory
     * @param IsPickupLocationShippingAddress $isPickupLocationShippingAddress
     * @param GetPickupLocationInterface $getPickupLocation
     * @param GetWebsiteCodeByStoreId $getWebsiteCodeByStoreId
     * @param IsInStorePickupDeliveryCartInterface $isInStorePickupDeliveryCart
     */
    public function __construct(
        ValidationResultFactory $validationResultFactory,
        IsPickupLocationShippingAddress $isPickupLocationShippingAddress,
        GetPickupLocationInterface $getPickupLocation,
        GetWebsiteCodeByStoreId $getWebsiteCodeByStoreId,
        IsInStorePickupDeliveryCartInterface $isInStorePickupDeliveryCart,
        GetSourceItemsBySkuAndSourceCodes $sourceItemsBySkuAndSourceCodes
    ) {
        $this->validationResultFactory = $validationResultFactory;
        $this->isPickupLocationShippingAddress = $isPickupLocationShippingAddress;
        $this->getPickupLocation = $getPickupLocation;
        $this->getWebsiteCodeByStoreId = $getWebsiteCodeByStoreId;
        $this->isInStorePickupDeliveryCart = $isInStorePickupDeliveryCart;
        $this->sourceItemsBySkuAndSourceCodes = $sourceItemsBySkuAndSourceCodes;
    }

    /**
     * @inheritdoc
     *
     * @return ValidationResult[]
     *
     * @throws NoSuchEntityException
     */
    public function validate(Quote $quote): array
    {
        $validationErrors = [];

        if (!$this->isInStorePickupDeliveryCart->execute($quote)) {
            return [$this->validationResultFactory->create(['errors' => $validationErrors])];
        }

        $address = $quote->getShippingAddress();
        $pickupLocation = $this->getPickupLocation($quote, $address);

        if (!$pickupLocation) {
            $validationErrors[] = __(
                'Quote does not have Pickup Location assigned.'
            );
        }

        if ($pickupLocation) {
            foreach ($quote->getItems() as $item) {
                $sourceStocks = $this->sourceItemsBySkuAndSourceCodes->execute(
                    $item->getSku(),
                    [$pickupLocation->getPickupLocationCode()]
                );

                if (count($sourceStocks) < 1) {
                    $validationErrors[] =__('The product "%1" has no stocks.', $item->getName());
                }

                /** @var SourceItemInterface $sourceStock */
                $sourceStock = array_pop($sourceStocks);

                if ((int)$sourceStock->getStatus() !== 1
                    || $sourceStock->getQuantity() < $item->getQty()
                ) {
                    $validationErrors[] = __(
                        'The product "%1" has insufficient stock in location %2',
                        $item->getName(),
                        $pickupLocation->getName()
                    );
                }
            }
        }

        return [$this->validationResultFactory->create(['errors' => $validationErrors])];
    }

    /**
     * Get Pickup Location entity, assigned to Shipping Address.
     *
     * @param CartInterface $quote
     * @param AddressInterface $address
     *
     * @return PickupLocationInterface|null
     * @throws NoSuchEntityException
     */
    protected function getPickupLocation(CartInterface $quote, AddressInterface $address): ?PickupLocationInterface
    {
        if (!$address->getExtensionAttributes() || !$address->getExtensionAttributes()->getPickupLocationCode()) {
            return null;
        }

        return $this->getPickupLocation->execute(
            $address->getExtensionAttributes()->getPickupLocationCode(),
            SalesChannelInterface::TYPE_WEBSITE,
            $this->getWebsiteCodeByStoreId->execute((int)$quote->getStoreId())
        );
    }
}
