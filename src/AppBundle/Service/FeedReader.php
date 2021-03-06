<?php

namespace AppBundle\Service;

use AppBundle\Entity\Channel;
use AppBundle\Entity\Feed;
use AppBundle\Entity\Item;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class FeedReader
{
    const NS_ITUNES = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
    /** @var Helper */
    private $helper;

    /** @var Feed */
    private $feed;

    /** @var EntityManagerInterface */
    private $entityManager;

    /** @var CategoryManager */
    private $categoryManager;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(Helper $helper)
    {
        $this->helper = $helper;
    }

    public function read(Feed $feed, EntityManagerInterface $entityManager, CategoryManager $categoryManager, LoggerInterface $logger)
    {
        $this->feed = $feed;
        $this->entityManager = $entityManager;
        $this->categoryManager = $categoryManager;
        $this->logger = $logger;

        if (!$feed->isEnabled()) {
            $logger->notice(sprintf('Feed %s is not enabled', $feed));

            return;
        }
        $now = new \DateTime();
        $nextReadAt = clone ($feed->getLastReadAt() ?: new \DateTime('2000-01-01'));
        $ttl = (int) $feed->getTtl();
        if ($ttl > 0) {
            $nextReadAt->add(new \DateInterval('PT'.$ttl.'M'));
        }
        if ($nextReadAt >= $now) {
            $logger->notice(sprintf('Next read of feed %s at %s', $feed, $nextReadAt->format(\DateTime::W3C)));

            return;
        }

        $channel = $this->readChannel();

        $feed
            ->setTtl((int) ($channel->getTtl() ?: 60))
            ->setLastReadAt(new \DateTime());
        $entityManager->persist($feed);
        $entityManager->flush();
    }

    /**
     * @return Channel
     */
    private function readChannel()
    {
        $data = new \SimpleXMLElement($this->feed->getUrl(), 0, true);
        if ($data) {
            $channel = $this->buildChannel($data->channel);
            if ($channel !== null) {
                $this->readItems($data->channel, $channel);
                $this->persist($channel);
            }
        }

        return $channel;
    }

    private function readItems(\SimpleXMLElement $el, Channel $channel)
    {
        foreach ($el->item as $child) {
            $item = $this->buildItem($child, $channel);

            if ($item !== null) {
                $this->persist($item);
                $this->notice(sprintf('Item: %s', $item));
            }
        }
    }

    private function buildChannel(\SimpleXMLElement $el)
    {
        $channel = $this->entityManager->getRepository(Channel::class)->findOneBy(['feed' => $this->feed]) ?: new Channel();
        $channel->setFeed($this->feed);

        $image = $el->image ? [
            'url' => (string) $el->image->url,
            'title' => (string) $el->image->title,
            'link' => (string) $el->image->link,
            'width' => $el->image->width ? (int) $el->image->width : null,
            'height' => $el->image->height ? (int) $el->image->height : null,
            'description' => $el->image->description ? (string) $el->image->description : null,
        ] : null;

        $itunes = $this->getItunes($el);

        $itunesImage = null;
        if ($itunes->image && $itunes->image->attributes()->href) {
            $itunesImage = [
                'href' => (string) $itunes->image->attributes()->href,
            ];
        }

        $channel
            ->setTitle((string) $el->title)
            ->setLink((string) $el->link)
            ->setDescription((string) $el->description)
            ->setLanguage((string) $el->language)
            ->setCopyright((string) $el->copyright)
            ->setManagingEditor((string) $el->managingEditor)
            ->setWebMaster((string) $el->webMaster)
            ->setPubDate($this->getDate($el->pubDate))
            ->setLastBuildDate($this->getDate($el->lastBuildDate))
            // ->setCategories(ArrayCollection $categories)
            ->setGenerator((string) $el->generator)
            ->setDocs((string) $el->docs)
            ->setCloud((string) $el->cloud)
            ->setTtl((int) ($el->ttl ?: 60))
            ->setImage($image)
            ->setRating((string) $el->rating)
            // ->setTextInput(array $textInput)
            // ->setSkipHours(array $skipHours)
            // ->setSkipDays(array $skipDays)
            ->setSubtitle((string) $el->subtitle)
            ->setSummary((string) $el->summary)
            ->setAuthor((string) ($el->author ?: $itunes->author))
            // ->setBlock((string) $el->block)
            // ->setComplete($complete)
            // ->setExplicit($explicit)
            ->setItunesImage($itunesImage);

        return $channel;
    }

    private function buildItem(\SimpleXMLElement $el, Channel $channel)
    {
        if (!(string) $el->guid) {
            $this->logger->notice('Item missing "guid"');

            return null;
        }
        if (!($el->enclosure && $el->enclosure->attributes()->url)) {
            $this->logger->notice('Item missing "enclosure"');

            return null;
        }

        $itunes = $this->getItunes($el);

        $guid = (string) $el->guid;
        $item = $this->getItem($guid);
        $item
            ->setTitle((string) $el->title)
            ->setLink((string) $el->link)
            ->setDescription((string) $el->description)
            ->setAuthor((string) ($el->author ?? $itunes->author ?? $channel->getAuthor()))
            ->setComments((string) $el->comments)
            ->setEnclosure([
                'url' => (string) $el->enclosure->attributes()->url,
                'type' => (string) $el->enclosure->attributes()->type,
                'length' => (int) $el->enclosure->attributes()->length,
            ])
            ->setGuid((string) $el->guid)
            ->setGuidIsPermaLink($this->getBoolean($el->guid->attributes()->isPermaLink))
            ->setPubDate($this->getDate($el->pubDate))
            ->setSubtitle((string) $itunes->subtitle)
            ->setSummary((string) $itunes->summary)
            ->setEpisode((int) $itunes->episode)
            ->setEpisodeType((string) $itunes->episodeType)
            ->setExplicit($this->getBoolean($itunes->explicit, ['explicit']))
            ->setImage($itunes->image ? ['href' => $itunes->image->attributes()->href] : null)
            ->setOrder((int) $itunes->order)
            ->setSeason((int) $itunes->season);

        if ($el->source) {
            $item->setSource([
                'url' => (string) $el->source->attributes()->url,
            ]);
        }

        if ($itunes !== null) {
            if ($itunes->duration) {
                $item->setDuration($this->helper->getDuration((string) $itunes->duration));
            }
            if ($itunes->category) {
                $names = [];
                foreach ($itunes->category as $category) {
                    $names[] = (string) $category->attributes()->text;
                }
                $names = array_filter($names);
                if (!empty($names)) {
                    $categories = $this->categoryManager->loadOrCreateTerms($names);
                    $item->setCategories($categories);
                }
            }
        }

        return $item;
    }

    private function getItem($guid)
    {
        $item = $this->entityManager->getRepository(Item::class)->findOneBy(['guid' => $guid]) ?: new Item();
        $item->setFeed($this->feed);

        return $item;
    }

    private function getItunes(\SimpleXMLElement $el)
    {
        // Create DOMDocument with all elements in itunes namespace.
        $dom = new \DOMDocument('1.0', 'utf-8');
        $root = $dom->createElementNS(self::NS_ITUNES, 'itunes:root');
        $dom->appendChild($root);
        foreach ($el->children(self::NS_ITUNES) as $child) {
            $node = $dom->importNode(dom_import_simplexml($child), true);
            $root->appendChild($node);
        }

        // Remove "itunes" namespace from the dom.
        $sxe = new \SimpleXMLElement($dom->saveXML());
        $root = $dom->childNodes->item(0);
        foreach ($sxe->getNamespaces(true) as $name => $uri) {
            if ($uri === self::NS_ITUNES) {
                $root->removeAttributeNS($uri, $name);
            }
        }

        return new \SimpleXMLElement($dom->saveXML());
    }

    private function getDate(\SimpleXMLElement $el)
    {
        $value = (string) $el;

        return $value !== null ? new \DateTime($value) : null;
    }

    private function getBoolean(\SimpleXMLElement $el = null, array $additionalTrues = [])
    {
        $value = (string) $el;
        $trues = ['true', 'yes'] + $additionalTrues;

        return in_array($value, $trues, true);
    }

    private function persist($entity)
    {
        $this->entityManager->persist($entity);
    }

    private function notice($messages)
    {
        $this->logger->notice($messages);
    }
}
