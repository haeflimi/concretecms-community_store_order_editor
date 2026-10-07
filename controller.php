<?php
namespace Concrete\Package\CommunityStoreOrderEditor;

use Concrete\Core\Application\UserInterface\Dashboard\Navigation\NavigationCache;
use Concrete\Core\Database\EntityManager\Provider\ProviderAggregateInterface;
use Concrete\Core\Database\EntityManager\Provider\StandardPackageProvider;
use Concrete\Core\Package\Package;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Single as SinglePage;

/**
 * Order editor for Community Store: a dashboard page to create orders by hand and to change every part of an
 * existing order (customer, dates, items, totals, payment state, status, notes, contact details). It sits next
 * to the store's own Orders page and does not alter it.
 */
class Controller extends Package implements ProviderAggregateInterface
{
    protected $pkgHandle = 'community_store_order_editor';
    protected $appVersionRequired = '9.0';
    protected $pkgVersion = '1.1.0';
    protected $pkgAutoloaderRegistries = [
        'src/Entity' => '\CommunityStoreOrderEditor\Entity',
        'src/Service' => '\CommunityStoreOrderEditor\Service',
    ];

    public function getPackageName()
    {
        return t('Community Store Order Editor');
    }

    public function getPackageDescription()
    {
        return t('Create and fully edit Community Store orders from the dashboard.');
    }

    public function getEntityManagerProvider()
    {
        return new StandardPackageProvider($this->app, $this, [
            'src/Entity' => 'CommunityStoreOrderEditor\Entity',
        ]);
    }

    public function install()
    {
        $installed = Package::getInstalledHandles();
        if (!is_array($installed) || !in_array('community_store', $installed, true)) {
            throw new \Exception(t('This package requires that Community Store be installed.'));
        }
        $pkg = parent::install();
        $this->installPages($pkg);
    }

    public function upgrade()
    {
        parent::upgrade();
        $this->refreshEntityMetadata();
        $this->installPages($this->getPackageEntity());
    }

    private function refreshEntityMetadata()
    {
        try {
            $em = $this->app->make('Doctrine\ORM\EntityManagerInterface');
            $cache = $em->getConfiguration()->getMetadataCacheImpl();
            if ($cache) {
                $cache->deleteAll();
            }
            $metadata = array_filter($em->getMetadataFactory()->getAllMetadata(), static function ($class) {
                return strpos($class->getName(), 'CommunityStoreOrderEditor\\') === 0;
            });
            $em->getProxyFactory()->generateProxyClasses($metadata);
        } catch (\Throwable $e) {
            $this->app->make('log')->warning('Community Store Order Editor: unable to refresh the entity metadata: ' . $e->getMessage());
        }
    }

    private function installPages($pkg)
    {
        $page = Page::getByPath('/dashboard/store/order_editor');
        if (!$page || $page->isError()) {
            $page = SinglePage::add('/dashboard/store/order_editor', $pkg);
        }
        if (!$page || $page->isError()) {
            return;
        }
        $page->update(['cName' => t('Order Editor'), 'cDescription' => t('Create, bulk-create and edit orders')]);
        $orders = Page::getByPath('/dashboard/store/orders');
        if ($orders && !$orders->isError()) {
            $page->movePageDisplayOrderToSibling($orders, 'after');
        }
        $this->app->make(NavigationCache::class)->clear();
    }
}
