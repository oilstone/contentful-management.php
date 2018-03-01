<?php

/**
 * This file is part of the contentful-management.php package.
 *
 * @copyright 2015-2017 Contentful GmbH
 * @license   MIT
 */
declare(strict_types=1);

namespace Contentful\Tests\Management\E2E;

use Contentful\Core\Api\DateTimeImmutable;
use Contentful\Core\File\File;
use Contentful\Core\File\LocalUploadFile;
use Contentful\Core\File\RemoteUploadFile;
use Contentful\Management\Query;
use Contentful\Management\Resource\Asset;
use Contentful\Management\Resource\Upload;
use Contentful\Tests\Management\BaseTestCase;
use function GuzzleHttp\Psr7\stream_for;

class AssetTest extends BaseTestCase
{
    /**
     * @vcr e2e_asset_get_one.json
     */
    public function testGetAsset()
    {
        $client = $this->getDefaultClient();

        $asset = $client->asset->get('2TEG7c2zYkSSuKmsqEwCS');
        $this->assertSame('Contentful Logo', $asset->getTitle('en-US'));
        $this->assertNull($asset->getDescription('en-US'));
        $link = $asset->asLink();
        $this->assertSame('2TEG7c2zYkSSuKmsqEwCS', $link->getId());
        $this->assertSame('Asset', $link->getLinkType());
        $this->assertSame(
            '//images.contentful.com/34luz0flcmxt/2TEG7c2zYkSSuKmsqEwCS/22da0779cac76ba6b74b5c2cbf084b97/contentful-logo-C395C545BF-seeklogo.com.png',
            $asset->getFile('en-US')->getUrl()
        );

        $sys = $asset->getSystemProperties();
        $this->assertSame('2TEG7c2zYkSSuKmsqEwCS', $sys->getId());
        $this->assertSame('Asset', $sys->getType());
        $this->assertSame(11, $sys->getVersion());
        $this->assertLink($this->defaultSpaceId, 'Space', $sys->getSpace());
        $this->assertSame('2017-08-22T15:41:45.127Z', (string) $sys->getCreatedAt());
        $this->assertSame('2017-08-22T15:42:20.946Z', (string) $sys->getUpdatedAt());
        $this->assertLink('1CECdY5ZhqJapGieg6QS9P', 'User', $sys->getCreatedBy());
        $this->assertLink('1CECdY5ZhqJapGieg6QS9P', 'User', $sys->getUpdatedBy());
        $this->assertSame(10, $sys->getPublishedVersion());
        $this->assertSame(1, $sys->getPublishedCounter());
        $this->assertSame('2017-08-22T15:42:20.946Z', (string) $sys->getPublishedAt());
        $this->assertSame('2017-08-22T15:42:20.946Z', (string) $sys->getFirstPublishedAt());
    }

    /**
     * @vcr e2e_asset_get_collection.json
     */
    public function testGetAssets()
    {
        $client = $this->getDefaultClient();
        $assets = $client->asset->getAll();

        $this->assertInstanceOf(Asset::class, $assets[0]);

        $query = (new Query())
            ->setLimit(1);
        $assets = $client->asset->getAll($query);
        $this->assertInstanceOf(Asset::class, $assets[0]);
        $this->assertCount(1, $assets);
    }

    /**
     * @vcr e2e_asset_create_update_process_publish_unpublish_archive_unarchive_delete.json
     */
    public function testCreateUpdateProcessPublishUnpublishArchiveUnarchiveDelete()
    {
        $client = $this->getDefaultClient();

        $asset = (new Asset())
            ->setTitle('en-US', 'An asset')
            ->setDescription('en-US', 'A really cool asset');

        $file = new RemoteUploadFile('contentful.svg', 'image/svg+xml', 'https://pbs.twimg.com/profile_images/488880764323250177/CrqV-RjR_normal.jpeg');

        $asset->setFile('en-US', $file);

        $client->asset->create($asset);
        $this->assertNotNull($asset->getId());
        $this->assertTrue($asset->getSystemProperties()->isDraft());
        $this->assertFalse($asset->getSystemProperties()->isPublished());
        $this->assertFalse($asset->getSystemProperties()->isUpdated());
        $this->assertFalse($asset->getSystemProperties()->isArchived());

        $asset->process('en-US');

        // Calls the API until the file is processed.
        // Limit is used because repeated requests will be recorded
        // and the same response will be returned
        $query = (new Query())
            ->where('sys.id', $asset->getId())
        ;
        $limit = 0;
        while ($asset->getFile('en-US') instanceof RemoteUploadFile) {
            ++$limit;
            $query->setLimit($limit);
            $asset = $client->asset->getAll($query)[0];

            // This is arbitrary
            if ($limit > 50) {
                throw new \RuntimeException(
                    'Repeated requests are not yielding a processed file, something is wrong.'
                );
            }
        }

        $asset->setTitle('en-US', 'Even better asset');

        $asset->update();

        $asset->archive();
        $this->assertSame(3, $asset->getSystemProperties()->getArchivedVersion());
        $this->assertTrue($asset->getSystemProperties()->isArchived());

        $asset->unarchive();
        $this->assertNull($asset->getSystemProperties()->getArchivedVersion());
        $this->assertFalse($asset->getSystemProperties()->isArchived());

        $asset->publish();
        $this->assertSame(5, $asset->getSystemProperties()->getPublishedVersion());
        $this->assertTrue($asset->getSystemProperties()->isPublished());

        $asset->unpublish();
        $this->assertNull($asset->getSystemProperties()->getPublishedVersion());
        $this->assertFalse($asset->getSystemProperties()->isPublished());

        $asset->delete();
    }

    /**
     * @vcr e2e_asset_create_with_id.json
     */
    public function testCreateAssetWithGivenId()
    {
        $client = $this->getDefaultClient();

        $asset = (new Asset())
            ->setTitle('en-US', 'An asset')
            ->setDescription('en-US', 'A really cool asset');

        $client->asset->create($asset, 'myCustomTestAsset');
        $this->assertSame('myCustomTestAsset', $asset->getId());

        $asset->delete();
    }

    /**
     * @vcr e2e_asset_upload.json
     */
    public function testUploadAsset()
    {
        $client = $this->getDefaultClient();

        // Creates upload using fopen
        $fopenUpload = new Upload(\fopen(__DIR__.'/../Fixtures/E2E/contentful-lab.svg', 'r'));
        $client->upload->create($fopenUpload);
        $this->assertNotNull($fopenUpload->getId());
        $this->assertInstanceOf(DateTimeImmutable::class, $fopenUpload->getSystemProperties()->getExpiresAt());
        $fopenUpload->delete();

        // Creates upload using stream
        $stream = stream_for(\file_get_contents(__DIR__.'/../Fixtures/E2E/contentful-logo.svg'));
        $streamUpload = new Upload($stream);
        $client->upload->create($streamUpload);
        $this->assertNotNull($streamUpload->getId());
        $this->assertInstanceOf(DateTimeImmutable::class, $streamUpload->getSystemProperties()->getExpiresAt());
        $streamUpload->delete();

        // Creates upload using string
        $upload = new Upload(\file_get_contents(__DIR__.'/../Fixtures/E2E/contentful-name.svg'));
        $client->upload->create($upload);
        $link = $upload->asLink();
        $this->assertSame($upload->getId(), $link->getId());
        $this->assertSame('Upload', $link->getLinkType());
        $this->assertNotNull($upload->getId());
        $this->assertInstanceOf(DateTimeImmutable::class, $upload->getSystemProperties()->getExpiresAt());

        $uploadFromFile = new LocalUploadFile('contentful.svg', 'image/svg+xml', $upload->asLink());

        $asset = new Asset();
        $asset->setTitle('en-US', 'Contentful');
        $asset->setFile('en-US', $uploadFromFile);

        $client->asset->create($asset);
        $asset->process('en-US');

        // Calls the API until the file is processed.
        // Limit is used because repeated requests will be recorded
        // and the same response will be returned
        $query = (new Query())
            ->where('sys.id', $asset->getId())
        ;
        $limit = 0;
        while ($asset->getFile('en-US') instanceof LocalUploadFile) {
            ++$limit;
            $query->setLimit($limit);
            $asset = $client->asset->getAll($query)[0];

            // This is arbitrary
            if ($limit > 50) {
                throw new \RuntimeException(
                    'Repeated requests are not yielding a processed file, something is wrong.'
                );
            }
        }

        $this->assertSame('contentful.svg', $asset->getFile('en-US')->getFileName());
        $this->assertSame('image/svg+xml', $asset->getFile('en-US')->getContentType());
        $this->assertContains('contentful.com', $asset->getFile('en-US')->getUrl());

        $upload = $client->resolveLink($upload->asLink());

        $upload->delete();
        $asset->delete();
    }

    /**
     * @vcr e2e_asset_text_file.json
     */
    public function testTextFileAsset()
    {
        $client = $this->getDefaultClient();

        $asset = $client->asset->get('1Gdj0yMYb60MuI6OCSkqMu');

        $this->assertSame('MIT', $asset->getTitle('en-US'));
        $this->assertSame('This is the MIT license', $asset->getDescription('en-US'));
        $file = $asset->getFile('en-US');
        $this->assertInstanceOf(File::class, $file);
        $this->assertSame('MIT.md', $file->getFileName());
        $this->assertSame('text/plain', $file->getContentType());
        $this->assertSame('//assets.contentful.com/34luz0flcmxt/1Gdj0yMYb60MuI6OCSkqMu/4211a1a1c41d6e477e4f8bcaea8b68ab/MIT.md', $file->getUrl());
        $this->assertSame(1065, $file->getSize());
    }
}
