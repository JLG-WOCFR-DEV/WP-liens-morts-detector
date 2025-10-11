<?php

namespace Tests\Scanner;

use BlcImageHostPolicy;
use BlcImageHostPolicyResult;
use BlcRemoteImagePolicy;
use BlcUploadPathResolver;
use Brain\Monkey\Functions;

class ImageUrlNormalizationServicesTest extends ScannerTestCase
{
    public function testHostPolicyDetectsSiteHost(): void
    {
        $policy = new BlcImageHostPolicy('example.com', 'cdn.example.com');

        $result = $policy->analyzeUrl('https://example.com/wp-content/uploads/2024/01/photo.jpg');

        $this->assertInstanceOf(BlcImageHostPolicyResult::class, $result);
        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSiteHost());
        $this->assertFalse($result->isRemoteHost());
    }

    public function testRemotePolicyAllowsSafeCdnWhenEnabled(): void
    {
        Functions\when('blc_is_safe_remote_host')->alias(fn() => true);

        $hostPolicy = new BlcImageHostPolicy('example.com', 'cdn.example.com');
        $hostResult = $hostPolicy->analyzeUrl('https://cdn.example.com/uploads/photo.jpg');

        $policy = new BlcRemoteImagePolicy(true);
        $decision = $policy->decide($hostResult);

        $this->assertTrue($decision->isAllowed());
        $this->assertFalse($decision->isRemoteUploadCandidate());
    }

    public function testRemotePolicyAllowsExternalHostWhenEnabled(): void
    {
        Functions\when('blc_is_safe_remote_host')->alias(fn() => true);

        $hostPolicy = new BlcImageHostPolicy('example.com', 'cdn.example.com');
        $hostResult = $hostPolicy->analyzeUrl('https://media.other-cdn.test/images/photo.jpg');

        $policy = new BlcRemoteImagePolicy(true);
        $decision = $policy->decide($hostResult);

        $this->assertTrue($decision->isAllowed());
        $this->assertTrue($decision->isRemoteUploadCandidate());
    }

    public function testRemotePolicyRejectsCdnWhenUnsafe(): void
    {
        Functions\when('blc_is_safe_remote_host')->alias(fn() => false);

        $hostPolicy = new BlcImageHostPolicy('example.com', 'cdn.example.com');
        $hostResult = $hostPolicy->analyzeUrl('https://cdn.example.com/uploads/photo.jpg');

        $policy = new BlcRemoteImagePolicy(true);
        $decision = $policy->decide($hostResult);

        $this->assertFalse($decision->isAllowed());
        $this->assertSame('remote_host_not_safe', $decision->getReason());
    }

    public function testUploadPathResolverResolvesAbsoluteUploadUrl(): void
    {
        $resolver = new BlcUploadPathResolver(
            'https://example.com/wp-content/uploads',
            '/var/www/html/wp-content/uploads',
            '/var/www/html'
        );

        $resolution = $resolver->resolve('https://example.com/wp-content/uploads/2024/01/photo.jpg', false);

        $this->assertTrue($resolution->isSuccessful());
        $this->assertSame('/var/www/html/wp-content/uploads/2024/01/photo.jpg', $resolution->getFilePath());
        $this->assertSame('2024/01/photo.jpg', $resolution->getDecodedRelativePath());
    }

    public function testUploadPathResolverRejectsTraversalInRelativePath(): void
    {
        $resolver = new BlcUploadPathResolver(
            'https://example.com/wp-content/uploads',
            '/var/www/html/wp-content/uploads',
            '/var/www/html'
        );

        $resolution = $resolver->resolve('https://example.com/wp-content/uploads/../config.php', false);

        $this->assertFalse($resolution->isSuccessful());
        $this->assertSame('path_traversal_detected', $resolution->getReason());
    }
}
