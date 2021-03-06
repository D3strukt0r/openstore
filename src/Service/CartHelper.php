<?php

namespace App\Service;

use App\Entity\Cart;
use App\Entity\DeliveryType;
use App\Entity\Order;
use App\Entity\Product;
use App\Entity\Store;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class CartHelper
{
    private $initialised = false;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var Request
     */
    private $request;

    /**
     * @var SessionInterface
     */
    private $session;

    /**
     * @var Store
     */
    private $store;

    /**
     * @var User|null
     */
    private $user;

    /**
     * @var Cart|null
     */
    private $cart;

    /**
     * @param EntityManagerInterface $em           The entity manager
     * @param RequestStack           $requestStack The request stack
     * @param SessionInterface       $session      The session
     */
    public function __construct(EntityManagerInterface $em, RequestStack $requestStack, SessionInterface $session)
    {
        $this->em = $em;
        $this->request = $requestStack->getCurrentRequest();
        $this->session = $session;
    }

    /**
     * Obligatory to add additional information, as it's impossible to add this through the service injector.
     *
     * @param Store     $store The store
     * @param User|null $user  The user
     */
    public function initialise(Store $store, User $user = null): void
    {
        $this->store = $store;
        $this->user = $user;
        $this->initialised = true;

        // If user exists (logged in)
        if (null !== $this->user) {
            /** @var Cart|null $cart */
            $cart = $this->em->getRepository(Cart::class)->findOneBy(['store' => $this->store, 'user' => $this->user]);
        } else {
            // If user doesn't exit (not logged in)
            /** @var Cart|null $cart */
            $id = $this->store->getId();
            $cart = $this->em->getRepository(Cart::class)->findOneBy(
                [
                    'store' => $this->store,
                    'id' => $this->session->get("cart[{$id}]"),
                ]
            )
            ;
        }

        // If cart exists
        if (null !== $cart) {
            $this->cart = $cart;
        } else { // If cart doesn't exist (not created yet)
            $this->createNewCart();
        }
    }

    /**
     * Clears the cart.
     */
    public function clearCart(): void
    {
        if (!$this->initialised) {
            return;
        }

        $this->cart->setProducts([]);
        $this->em->flush();
    }

    /**
     * Add a product to the cart.
     *
     * @param Product $product (Required) to know which product
     * @param int     $count   (Optional) Amount to be added
     */
    public function addToCart(Product $product, int $count = 1): void
    {
        if (!$this->initialised) {
            return;
        }

        $products = $this->cart->getProducts();

        // How many are already in the cart?
        $alreadyInCart = 0;
        if (isset($products[$product->getId()])) {
            $alreadyInCart = $products[$product->getId()]['count'];
        }

        // Add it to the cart
        $products[$product->getId()]['id'] = $product->getId();
        $products[$product->getId()]['count'] = $alreadyInCart + $count;

        $this->cart->setProducts($products);
        $this->em->flush();
    }

    /**
     * Remove a product from the cart.
     *
     * @param int      $product_id (Required) to know which product
     * @param int|null $count      (Optional) Amount to be removed
     */
    public function removeFromCart(int $product_id, ?int $count = null): void
    {
        if (!$this->initialised) {
            return;
        }

        $products = $this->cart->getProducts();

        if (isset($products[$product_id])) {
            if ((null !== $count || $count > 0) && $products[$product_id]['count'] > $count) {
                // Only remove the amount
                $products[$product_id]['count'] -= $count;
            } else {
                // Remove the product
                unset($products[$product_id]);
            }
            $this->cart->setProducts($products);
            $this->em->flush();
        }
    }

    /**
     * @param bool $additional_info
     * @param bool $add_total
     *
     * @return array|null
     */
    public function getCart($additional_info = false, $add_total = false)
    {
        if (!$this->initialised) {
            return [];
        }

        $products = $this->cart->getProducts();

        if (0 === count($products)) {
            if ($add_total) {
                return [
                    'system' => [
                        'id' => 0, // Needed, so it won't be displayed in the checkout
                        'total_count' => 0,
                        'total_price' => 0,
                    ],
                ];
            }

            return [];
        }

        if ($additional_info) {
            $totalCount = 0;
            $totalPrice = 0;

            $userLanguage = $this->request->getLocale();
            $userCurrency = $this->request->getSession()->get('_currency');

            foreach ($products as $key => $item) {
                /** @var Product $product */
                $product = $this->em->getRepository(Product::class)->findOneBy(['id' => $item['id']]);
                $products[$key] = $product->toArray();

                $products[$key]['name'] = $product->getName($userLanguage);
                $products[$key]['description'] = $product->getDescription($userLanguage);
                $products[$key]['price'] = $product->getPrice($userCurrency);
                $products[$key]['in_sale'] = $product->isInSale($userCurrency);
                $products[$key]['price_sale'] = $products[$key]['in_sale'] ? $product->getSalePrice(
                    $userCurrency
                ) : false;

                $products[$key]['in_cart'] = $item['count'];
                $products[$key]['subtotal'] =
                    $item['count'] * ($products[$key]['in_sale']
                        ? $products[$key]['price_sale']
                        : $products[$key]['price']);

                if ($add_total) {
                    $totalCount += $products[$key]['in_cart'];
                    $totalPrice += $products[$key]['subtotal'];
                }
            }

            if ($add_total) {
                $products['system']['id'] = 0; // Needed, so it won't be displayed in the checkout
                $products['system']['total_count'] = $totalCount;
                $products['system']['total_price'] = $totalPrice;
            }
        }

        return $products;
    }

    /**
     * @param array $order_info
     */
    public function makeOrder($order_info): void
    {
        if (!$this->initialised) {
            return;
        }

        // Update "stock_available" for every product in cart
        foreach ($this->cart->getProducts() as $key => $productInfo) {
            /** @var Product $product */
            $product = $this->em->getRepository(Product::class)->findOneBy(['id' => $productInfo['id']]);
            $currentStock = $product->getStock();
            $newStock = $currentStock - $productInfo['count'];
            $product->setStock($newStock);
        }
        $this->em->flush();

        // Save the order
        /** @var DeliveryType|null $deliveryType */
        $deliveryType = $this->em->getRepository(DeliveryType::class)->findOneBy(
            ['id' => $order_info['delivery_type']]
        )
        ;

        $newOrder = new Order();
        $newOrder
            ->setStore($this->store)
            ->setName($order_info['name'])
            ->setEmail($order_info['email'])
            ->setPhone($order_info['phone'])
            ->setStreet($order_info['location_street'] . ' ' . $order_info['location_street_number'])
            ->setZipCode($order_info['location_postal_code'])
            ->setCity($order_info['location_city'])
            ->setCountry($order_info['location_country'])
            ->setDeliveryType($deliveryType)
            ->setProductList($this->cart->getProducts())
        ;
        $this->em->persist($newOrder);
        $this->em->flush();
    }

    /**
     * Creates a new cart in the database.
     */
    private function createNewCart(): void
    {
        if (!$this->initialised) {
            return;
        }

        $newCart = (new Cart())
            ->setStore($this->store)
            ->setUser($this->user)
            ->setProducts([])
        ;

        $this->em->persist($newCart);
        $this->em->flush();

        if (null === $this->user) {
            $id = $this->session->getId();
            $this->session->set("cart[{$id}]", $newCart->getId());
        }

        $this->cart = $newCart;
    }
}
