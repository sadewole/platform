<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Test\Cart\Common;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryCalculator;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Cart\Tax\TaxAmountCalculator;
use Shopware\Core\Checkout\Cart\Tax\TaxDetector;
use Shopware\Core\Checkout\CheckoutContext;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerGroup\CustomerGroupEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Content\Catalog\CatalogCollection;
use Shopware\Core\Content\Catalog\CatalogEntity;
use Shopware\Core\Content\Product\Cart\ProductGateway;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Struct\Uuid;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\Locale\LocaleEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\Tax\TaxCollection;
use Shopware\Core\System\Tax\TaxEntity;

class Generator extends TestCase
{
    public static function createCheckoutContext(
        $currentCustomerGroup = null,
        $fallbackCustomerGroup = null,
        $salesChannel = null,
        $currency = null,
        $taxes = null,
        $country = null,
        $state = null,
        $shipping = null,
        $language = null,
        $fallbackLanguage = null,
        $paymentMethod = null
    ): CheckoutContext {
        if ($salesChannel === null) {
            $salesChannel = new SalesChannelEntity();
            $salesChannel->setId('ffa32a50e2d04cf38389a53f8d6cd594');
            $salesChannel->setTaxCalculationType(TaxAmountCalculator::CALCULATION_HORIZONTAL);

            $catalogs = new CatalogCollection();
            $catalog = new CatalogEntity();
            $catalog->setName('generated catalog');
            $catalog->setId(Defaults::CATALOG);

            $salesChannel->setCatalogs($catalogs);
        }

        $currency = $currency ?: (new CurrencyEntity())->assign([
            'id' => '4c8eba11-bd35-46d7-86af-bed481a6e665',
            'factor' => 1,
        ]);

        $currency->setFactor(1);

        if (!$currentCustomerGroup) {
            $currentCustomerGroup = new CustomerGroupEntity();
            $currentCustomerGroup->setId(Defaults::FALLBACK_CUSTOMER_GROUP);
            $currentCustomerGroup->setDisplayGross(true);
        }

        if (!$fallbackCustomerGroup) {
            $fallbackCustomerGroup = new CustomerGroupEntity();
            $fallbackCustomerGroup->setId(Defaults::FALLBACK_CUSTOMER_GROUP);
            $currentCustomerGroup->setDisplayGross(true);
        }

        if (!$taxes) {
            $tax = new TaxEntity();
            $tax->setId('49260353-68e3-4d9f-a695-e017d7a231b9');
            $tax->setName('test');
            $tax->setTaxRate(19.0);

            $taxes = new TaxCollection([$tax]);
        }

        if (!$country) {
            $country = new CountryEntity();
            $country->setId('5cff02b1-0297-41a4-891c-430bcd9e3603');
            $country->setTaxFree(false);
            $country->setName('Germany');
        }
        if (!$state) {
            $state = new CountryStateEntity();
            $state->setId('bd5e2dcf-547e-4df6-bb1f-f58a554bc69e');
            $state->setCountryId($country->getId());
        }

        if (!$shipping) {
            $shipping = new CustomerAddressEntity();
            $shipping->setCountry($country);
            $shipping->setCountryState($state);
        }

        if (!$language) {
            $locale = new LocaleEntity();
            $locale->setCode('en_GB');

            $language = new LanguageEntity();
            $language->setId(Defaults::LANGUAGE_EN);
            $language->setLocale($locale);
            $language->setName('Language 1');
        }

        if (!$fallbackLanguage) {
            $locale = new LocaleEntity();
            $locale->setCode('en_GB');

            $fallbackLanguage = new LanguageEntity();
            $fallbackLanguage->setLocale($locale);
            $fallbackLanguage->setName('Fallback Language 1');
        }

        if (!$paymentMethod) {
            $paymentMethod = (new PaymentMethodEntity())->assign(['id' => '19d144ff-e15f-4772-860d-59fca7f207c1']);
        }

        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId('8beeb66e9dda46b18891a059257a590e');
        $shippingMethod->setCalculation(DeliveryCalculator::CALCULATION_BY_PRICE);
        $shippingMethod->setMinDeliveryTime(1);
        $shippingMethod->setMaxDeliveryTime(2);

        $customer = (new CustomerEntity())->assign(['id' => Uuid::uuid4()->getHex()]);
        $customer->setId(Uuid::uuid4()->getHex());
        $customer->setGroup($currentCustomerGroup);

        return new CheckoutContext(
            Uuid::uuid4()->toString(),
            $salesChannel,
            $language,
            $fallbackLanguage,
            $currency,
            $currentCustomerGroup,
            $fallbackCustomerGroup,
            $taxes,
            $paymentMethod,
            $shippingMethod,
            ShippingLocation::createFromAddress($shipping),
            $customer,
            []
        );
    }

    public static function createGrossPriceDetector(): TaxDetector
    {
        $self = new self();

        return $self->createTaxDetector(true, false);
    }

    public static function createNetPriceDetector(): TaxDetector
    {
        $self = new self();

        return $self->createTaxDetector(false, false);
    }

    public static function createNetDeliveryDetector(): TaxDetector
    {
        $self = new self();

        return $self->createTaxDetector(false, true);
    }

    /**
     * @param QuantityPriceDefinition[] $priceDefinitions indexed by product number
     *
     * @return ProductGateway
     */
    public function createProductPriceGateway($priceDefinitions): ProductGateway
    {
        /** @var MockObject|ProductGateway $mock */
        $mock = $this->createMock(ProductGateway::class);
        $mock
            ->method('get')
            ->will(static::returnValue($priceDefinitions));

        return $mock;
    }

    public static function createCart(): Cart
    {
        $cart = new Cart('test', 'test');
        $cart->setLineItems(
            new LineItemCollection([
                (new LineItem('A', 'product', 27))
                    ->setPrice(new CalculatedPrice(10, 270, new CalculatedTaxCollection(), new TaxRuleCollection(), 27)),

                (new LineItem('B', 'test', 5))
                    ->setGood(false)
                    ->setPrice(new CalculatedPrice(0, 0, new CalculatedTaxCollection(), new TaxRuleCollection())),
            ])
        );
        $cart->setPrice(
            new CartPrice(275, 275, 0, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS)
        );

        return $cart;
    }

    private function createTaxDetector($useGross, $isNetDelivery): TaxDetector
    {
        /** @var MockObject|TaxDetector $mock */
        $mock = $this->createMock(TaxDetector::class);
        $mock
            ->method('useGross')
            ->will(static::returnValue($useGross));

        $mock
            ->method('isNetDelivery')
            ->will(static::returnValue($isNetDelivery));

        return $mock;
    }
}
