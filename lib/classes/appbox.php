<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2015 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Alchemy\Phrasea\Application;
use Alchemy\Phrasea\Core\Configuration\AccessRestriction;
use Alchemy\Phrasea\Databox\DataboxRepositoryInterface;
use Doctrine\ORM\Tools\SchemaTool;
use MediaAlchemyst\Alchemyst;
use MediaAlchemyst\Specification\Image as ImageSpecification;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File as SymfoFile;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use vierbergenlars\SemVer\version;

class appbox extends base
{
    /** @var int */
    protected $id;

    /**
     * constant defining the app type
     */
    const BASE_TYPE = self::APPLICATION_BOX;

    protected $cache;
    protected $connection;
    protected $app;

    protected $databoxes;

    const CACHE_LIST_BASES = 'list_bases';
    const CACHE_SBAS_IDS = 'sbas_ids';

    public function __construct(Application $app)
    {
        $connexion = $app['conf']->get(['main', 'database']);
        parent::__construct($app, $app['db.provider']($connexion));

        $this->host = $connexion['host'];
        $this->port = $connexion['port'];
        $this->user = $connexion['user'];
        $this->passwd = $connexion['password'];
        $this->dbname = $connexion['dbname'];
    }

    public function write_collection_pic(Alchemyst $alchemyst, Filesystem $filesystem, collection $collection, SymfoFile $pathfile = null, $pic_type)
    {
        $filename = null;

        if (!is_null($pathfile)) {

            if (!in_array(mb_strtolower($pathfile->getMimeType()), ['image/gif', 'image/png', 'image/jpeg', 'image/jpg', 'image/pjpeg'])) {
                throw new \InvalidArgumentException('Invalid file format');
            }

            $filename = $pathfile->getPathname();

            if ($pic_type === collection::PIC_LOGO) {
                //resize collection logo
                $imageSpec = new ImageSpecification();

                $media = $this->app->getMediaFromUri($filename);

                if ($media->getWidth() > 120 || $media->getHeight() > 24) {
                    $imageSpec->setResizeMode(ImageSpecification::RESIZE_MODE_INBOUND_FIXEDRATIO);
                    $imageSpec->setDimensions(120, 24);
                }

                $tmp = tempnam(sys_get_temp_dir(), 'tmpdatabox') . '.jpg';

                try {
                    $alchemyst->turninto($pathfile->getPathname(), $tmp, $imageSpec);
                    $filename = $tmp;
                } catch (\MediaAlchemyst\Exception $e) {

                }
            } elseif ($pic_type === collection::PIC_PRESENTATION) {
                //resize collection logo
                $imageSpec = new ImageSpecification();
                $imageSpec->setResizeMode(ImageSpecification::RESIZE_MODE_INBOUND_FIXEDRATIO);
                $imageSpec->setDimensions(650, 200);

                $tmp = tempnam(sys_get_temp_dir(), 'tmpdatabox') . '.jpg';

                try {
                    $alchemyst->turninto($pathfile->getPathname(), $tmp, $imageSpec);
                    $filename = $tmp;
                } catch (\MediaAlchemyst\Exception $e) {

                }
            }
        }

        switch ($pic_type) {
            case collection::PIC_WM;
                $collection->reset_watermark();
                break;
            case collection::PIC_LOGO:
            case collection::PIC_PRESENTATION:
                break;
            case collection::PIC_STAMP:
                $collection->reset_stamp();
                break;
            default:
                throw new \InvalidArgumentException('unknown pic_type');
                break;
        }

        if ($pic_type == collection::PIC_LOGO) {
            $collection->update_logo($pathfile);
        }

        $file = $this->app['root.path'] . '/config/' . $pic_type . '/' . $collection->get_base_id();
        $custom_path = $this->app['root.path'] . '/www/custom/' . $pic_type . '/' . $collection->get_base_id();

        foreach ([$file, $custom_path] as $target) {

            if (is_file($target)) {

                $filesystem->remove($target);
            }

            if (null === $target || null === $filename) {
                continue;
            }

            $filesystem->mkdir(dirname($target), 0750);
            $filesystem->copy($filename, $target, true);
            $filesystem->chmod($target, 0760);
        }

        return $this;
    }

    public function write_databox_pic(Alchemyst $alchemyst, Filesystem $filesystem, databox $databox, SymfoFile $pathfile = null, $pic_type)
    {
        $filename = null;

        if (!is_null($pathfile)) {

            if (!in_array(mb_strtolower($pathfile->getMimeType()), ['image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/gif'])) {
                throw new \InvalidArgumentException('Invalid file format');
            }
        }

        if (!in_array($pic_type, [databox::PIC_PDF])) {
            throw new \InvalidArgumentException('unknown pic_type');
        }

        if ($pathfile) {

            $filename = $pathfile->getPathname();

            $imageSpec = new ImageSpecification();
            $imageSpec->setResizeMode(ImageSpecification::RESIZE_MODE_INBOUND_FIXEDRATIO);
            $imageSpec->setDimensions(120, 35);

            $tmp = tempnam(sys_get_temp_dir(), 'tmpdatabox') . '.jpg';

            try {
                $alchemyst->turninto($pathfile->getPathname(), $tmp, $imageSpec);
                $filename = $tmp;
            } catch (\MediaAlchemyst\Exception $e) {

            }
        }

        $file = $this->app['root.path'] . '/config/minilogos/' . $pic_type . '_' . $databox->get_sbas_id() . '.jpg';
        $custom_path = $this->app['root.path'] . '/www/custom/minilogos/' . $pic_type . '_' . $databox->get_sbas_id() . '.jpg';

        foreach ([$file, $custom_path] as $target) {

            if (is_file($target)) {
                $filesystem->remove($target);
            }

            if (is_null($filename)) {
                continue;
            }

            $filesystem->mkdir(dirname($target));
            $filesystem->copy($filename, $target);
            $filesystem->chmod($target, 0760);
        }

        $databox->delete_data_from_cache('printLogo');

        return $this;
    }

    /**
     *
     * @param  collection $collection
     * @param  <type>     $ordre
     * @return appbox
     */
    public function set_collection_order(collection $collection, $ordre)
    {
        $sqlupd = "UPDATE bas SET ord = :ordre WHERE base_id = :base_id";
        $stmt = $this->get_connection()->prepare($sqlupd);
        $stmt->execute([':ordre'   => $ordre, ':base_id' => $collection->get_base_id()]);
        $stmt->closeCursor();

        $collection->get_databox()->delete_data_from_cache(\databox::CACHE_COLLECTIONS);

        return $this;
    }

    /**
     *
     * @param  databox $databox
     * @param  <type>  $boolean
     * @return appbox
     */
    public function set_databox_indexable(databox $databox, $boolean)
    {
        $boolean = !!$boolean;
        $sql = 'UPDATE sbas SET indexable = :indexable WHERE sbas_id = :sbas_id';

        $stmt = $this->get_connection()->prepare($sql);
        $stmt->execute([
            ':indexable' => ($boolean ? '1' : '0'),
            ':sbas_id'   => $databox->get_sbas_id()
        ]);
        $stmt->closeCursor();

        return $this;
    }

    /**
     *
     * @param  databox $databox
     * @return <type>
     */
    public function is_databox_indexable(databox $databox)
    {
        $sql = 'SELECT indexable FROM sbas WHERE sbas_id = :sbas_id';

        $stmt = $this->get_connection()->prepare($sql);
        $stmt->execute([':sbas_id' => $databox->get_sbas_id()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $indexable = $row ? $row['indexable'] : null;

        return (Boolean) $indexable;
    }

    /**
     *
     * @return const
     */
    public function get_base_type()
    {
        return self::BASE_TYPE;
    }

    public function forceUpgrade(Setup_Upgrade $upgrader, Application $app)
    {
        $from_version = $this->get_version();

        $app['phraseanet.cache-service']->flushAll();

        // Executes stuff before applying patches
        $app['phraseanet.pre-schema-upgrader']->apply($app);

        $finder = new Finder();
        $in = [];
        $path = $app['cache.path'];
        $in[] = $path.'/minify/';
        $in[] = $path.'/twig/';
        $in[] = $path.'/translations/';
        $in[] = $path.'/profiler/';
        $in[] = $path.'/doctrine/';
        $in[] = $path.'/serializer/';
        $finder->in(array_filter($in, function($path) {
            return is_dir($path);
        }))
            ->depth(0)
            ->ignoreVCS(true)
            ->ignoreDotFiles(true);


        $app['filesystem']->remove($finder);

        foreach ([
            'config/custom_files/' => 'www/custom/',
            'config/minilogos/'    => 'www/custom/minilogos/',
            'config/stamp/'        => 'www/custom/stamp/',
            'config/status/'       => 'www/custom/status/',
            'config/wm/'           => 'www/custom/wm/',
        ] as $source => $target) {
            $app['filesystem']->mirror($this->app['root.path'] . '/' . $source, $this->app['root.path'] . '/' . $target, null, array('override' => true));
        }

        // do not apply patches
        // just update old database schema
        // it is need before applying patches
        $advices = $this->upgradeDB(false, $app);

        foreach ($this->get_databoxes() as $s) {
            $advices = array_merge($advices, $s->upgradeDB(false, $app));
        }

        // then apply patches
        $advices = $this->upgradeDB(true, $app);

        foreach ($this->get_databoxes() as $s) {
            $advices = array_merge($advices, $s->upgradeDB(true, $app));
        }

        $this->post_upgrade($app);

        $app['phraseanet.cache-service']->flushAll();

        if ($app['orm.em']->getConnection()->getDatabasePlatform()->supportsAlterTable()) {
            $tool = new SchemaTool($app['orm.em']);
            $metas = $app['orm.em']->getMetadataFactory()->getAllMetadata();
            $tool->updateSchema($metas, true);
        }

        if (version::lt($from_version, '3.1')) {
            $upgrader->addRecommendation($app->trans('Your install requires data migration, please execute the following command'), 'bin/setup system:upgrade-datas --from=3.1');
        } elseif (version::lt($from_version, '3.5')) {
            $upgrader->addRecommendation($app->trans('Your install requires data migration, please execute the following command'), 'bin/setup system:upgrade-datas --from=3.5');
        }

        if (version::lt($from_version, '3.7')) {
            $upgrader->addRecommendation($app->trans('Your install might need to re-read technical datas'), 'bin/console records:rescan-technical-datas');
            $upgrader->addRecommendation($app->trans('Your install might need to build some sub-definitions'), 'bin/console records:build-missing-subdefs');
        }

        return $advices;
    }

    protected function post_upgrade(Application $app)
    {
        $this->apply_patches($this->get_version(), $app['phraseanet.version']->getNumber(), true, $app);
        $this->setVersion($app['phraseanet.version']);

        foreach ($this->get_databoxes() as $databox) {
            $databox->apply_patches($databox->get_version(), $app['phraseanet.version']->getNumber(), true, $app);
            $databox->setVersion($app['phraseanet.version']);
        }

        return $this;
    }

    /**
     * @return databox[]
     */
    public function get_databoxes()
    {
        if (!$this->databoxes) {
            $this->databoxes = $this->getAccessRestriction()
                ->filterDataboxAvailable($this->getDataboxRepository()->findAll());
        }

        return $this->databoxes;
    }

    public function get_databox($sbas_id)
    {
        $databoxes = $this->get_databoxes();

        if (!isset($databoxes[$sbas_id]) && !array_key_exists($sbas_id, $databoxes)) {
            throw new NotFoundHttpException('Databox `' . $sbas_id . '` not found');
        }

        return $databoxes[$sbas_id];
    }

    /**
     * @param string $option
     * @return string
     */
    public function get_cache_key($option = null)
    {
        return 'appbox_' . ($option ? $option . '_' : '');
    }

    public function delete_data_from_cache($option = null)
    {
        if ($option === appbox::CACHE_LIST_BASES) {
            $this->databoxes = null;
        }

        parent::delete_data_from_cache($option);
    }

    /**
     * @return AccessRestriction
     */
    private function getAccessRestriction()
    {
        return $this->app['conf.restrictions'];
    }

    /**
     * @return DataboxRepositoryInterface
     */
    private function getDataboxRepository()
    {
        return $this->app['repo.databoxes'];
    }
}
