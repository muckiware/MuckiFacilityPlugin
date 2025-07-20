<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2025 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Entity;

class MediaLocationEntity
{
    protected string $relativePathOrigin;
    protected string $absolutePathOrigin;
    protected string $relativePathWebp;
    protected string $absolutePathWebp;
    protected string $mediaId;
    protected string $urlOrigin;
    protected string $urlWebp;
    protected string $imageHash;

    public function getRelativePathOrigin(): string
    {
        return $this->relativePathOrigin;
    }

    public function setRelativePathOrigin(string $relativePathOrigin): void
    {
        $this->relativePathOrigin = $relativePathOrigin;
    }

    public function getAbsolutePathOrigin(): string
    {
        return $this->absolutePathOrigin;
    }

    public function setAbsolutePathOrigin(string $absolutePathOrigin): void
    {
        $this->absolutePathOrigin = $absolutePathOrigin;
    }

    public function getRelativePathWebp(): string
    {
        return $this->relativePathWebp;
    }

    public function setRelativePathWebp(string $relativePathWebp): void
    {
        $this->relativePathWebp = $relativePathWebp;
    }

    public function getAbsolutePathWebp(): string
    {
        return $this->absolutePathWebp;
    }

    public function setAbsolutePathWebp(string $absolutePathWebp): void
    {
        $this->absolutePathWebp = $absolutePathWebp;
    }

    public function getMediaId(): string
    {
        return $this->mediaId;
    }

    public function setMediaId(string $mediaId): void
    {
        $this->mediaId = $mediaId;
    }

    public function getUrlOrigin(): string
    {
        return $this->urlOrigin;
    }

    public function setUrlOrigin(string $urlOrigin): void
    {
        $this->urlOrigin = $urlOrigin;
    }

    public function getUrlWebp(): string
    {
        return $this->urlWebp;
    }

    public function setUrlWebp(string $urlWebp): void
    {
        $this->urlWebp = $urlWebp;
    }

    public function getImageHash(): string
    {
        return $this->imageHash;
    }

    public function setImageHash(string $imageHash): void
    {
        $this->imageHash = $imageHash;
    }
}
