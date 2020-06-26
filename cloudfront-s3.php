<?php
declare(strict_types=1);

require_once './vendor/autoload.php';

$dotenv = new \Symfony\Component\Dotenv\Dotenv();
$dotenv->usePutenv(true);
$dotenv->loadEnv(__DIR__ . '/.env');

$aws = new \Aws\Sdk([
    'region'      => 'ap-northeast-1',
    'version'     => 'latest',
    'credentials' => [
        'key'    => getenv('AWS_ACCESS_KEY_ID'),
        'secret' => getenv('AWS_SECRET_ACCESS_KEY')
    ]
]);

$s3client = $aws->createS3();
$acmClient = $aws->createAcm([
    'region' => 'us-east-1'
]);
$cloudFrontClient = $aws->createCloudFront();

$fqdn = getenv('SITE_FQDN');

$bucket_exist = $s3client->doesBucketExist($fqdn);
if ($bucket_exist) {
    print 'Exists S3 bucket.' . PHP_EOL;
} else {
    // create bucket
    print 'Create S3 bucket.' . PHP_EOL;
    $result = $s3client->createBucket([
        'ACL'    => 'public-read',
        'Bucket' => $fqdn,
    ]);
    if ($result['@metadata']['statusCode'] !== 200) {
        print_r($result);
        exit();
    }

    // CloudFront を通すので不要
//    // bucket website
//    $result = $s3client->putBucketWebsite([
//        'Bucket' => $fqdn,
//        'WebsiteConfiguration' => [
//            'ErrorDocument' => [
//                'Key' => 'error.html'
//            ],
//            'IndexDocument' => [
//                'Suffix' => 'index.html'
//            ]
//        ]
//    ]);
//    if ($result['@metadata']['statusCode'] !== 200) {
//        print_r($result);
//        exit();
//    }
//    $s3_url = sprintf('http://%s.s3-website-ap-northeast-1.amazonaws.com', $fqdn);
//    print $s3_url . PHP_EOL;

    // upload index.html
    print 'Upload index.html to S3 bucket.' . PHP_EOL;
    $result = $s3client->putObject([
        'ACL'    => 'public-read',
        'Bucket' => $fqdn,
        'Key'    => 'index.html',
        'Body'   => fopen('index.html', 'rb')
    ]);
    if ($result['@metadata']['statusCode'] !== 200) {
        print_r($result);
        exit();
    }

    // upload 404.html
    print 'Upload 404.html to S3 bucket.' . PHP_EOL;
    $result = $s3client->putObject([
        'ACL'    => 'public-read',
        'Bucket' => $fqdn,
        'Key'    => '404.html',
        'Body'   => fopen('404.html', 'rb')
    ]);
    if ($result['@metadata']['statusCode'] !== 200) {
        print_r($result);
        exit();
    }
}

// request certificate
$result = $acmClient->listCertificates([]);
if ($result['@metadata']['statusCode'] !== 200) {
    print_r($result);
    exit();
}

$certificate_arn = null;
foreach ($result['CertificateSummaryList'] as $certificate_summary) {
    if ($certificate_summary['DomainName'] !== $fqdn) {
        continue;
    }
    $certificate_arn = $certificate_summary['CertificateArn'];
    break;
}

if ($certificate_arn === null) {
    print 'Create certificate.' . PHP_EOL;
    $result = $acmClient->requestCertificate([
        'DomainName'       => $fqdn,
        'IdempotencyToken' => '12345', // 証明書作成リクエストを識別するトークン。同じものを使用するとリクエストが重複しない
        'Options'          => [
            'CertificateTransparencyLoggingPreference' => 'ENABLED',
        ],
        'ValidationMethod' => 'DNS',
    ]);
    if ($result['@metadata']['statusCode'] !== 200) {
        print_r($result);
        exit();
    }
    $certificate_arn = $result['CertificateArn'];
} else {
    print 'Exists Certificate.' . PHP_EOL;
}

// check certificate
$result = $acmClient->describeCertificate([
    'CertificateArn' => $certificate_arn
]);
if ($result['@metadata']['statusCode'] !== 200) {
    print_r($result);
    exit();
}

/** @var string $certificate_status PENDING_VALIDATION|ISSUED|INACTIVE|EXPIRED|VALIDATION_TIMED_OUT|REVOKED|FAILED */
$certificate_status = $result['Certificate']['Status'];

if ($certificate_status === 'PENDING_VALIDATION') {
    $certificate_resource_record = $result['Certificate']['DomainValidationOptions'][0]['ResourceRecord'];
    print 'ACM is pending validation.' . PHP_EOL;
    print sprintf('%s %s %s',
            $certificate_resource_record['Name'],
            $certificate_resource_record['Type'],
            $certificate_resource_record['Value']) . PHP_EOL;
    exit();
}
if ($certificate_status !== 'ISSUED') {
    print 'ACM is is not issued.' . PHP_EOL;
    print $certificate_status . PHP_EOL;
    exit();
}

// CloudFront
$result = $cloudFrontClient->listDistributions();
if ($result['@metadata']['statusCode'] !== 200) {
    print_r($result);
    exit();
}
$cloud_front_id = null;
foreach ($result['DistributionList']['Items'] as $item) {
    foreach ($item['Aliases']['Items'] as $item_fqdn) {
        if ($fqdn !== $item_fqdn) {
            continue;
        }
        $cloud_front_id = $item['Id'];
        break;
    }
    if ($cloud_front_id !== null) {
        break;
    }
}

if ($cloud_front_id === null) {
    print 'Distribution CloudFront.' . PHP_EOL;
    $result = $cloudFrontClient->createDistribution([
        'DistributionConfig' => [
            'CallerReference' => '1234', // CloudFront作成リクエストを識別するトークン。同じものを使用するとリクエストが重複しない

            'Enabled'       => true,
            'IsIPV6Enabled' => true,
            'Aliases'       => [
                'Quantity' => 1,
                'Items'    => [
                    $fqdn
                ]
            ],
            'Comment'       => sprintf('for s3 bucket (%s)', $fqdn),

            'Origins'           => [
                'Items'    => [
                    [
                        'DomainName'         => sprintf('%s.s3.amazonaws.com', $fqdn),
                        'Id'                 => $fqdn,
                        'OriginPath'         => '',
                        'CustomOriginConfig' => [
                            'HTTPPort'               => 80,
                            'HTTPSPort'              => 443,
                            'OriginKeepaliveTimeout' => 5,
                            'OriginProtocolPolicy'   => "http-only",
                            'OriginReadTimeout'      => 60,
                            'OriginSslProtocols'     => [
                                'Items'    => ['TLSv1', 'TLSv1.1', 'TLSv1.2'],
                                'Quantity' => 3
                            ]
                        ]
                    ],
                ],
                'Quantity' => 1,
            ],
            'ViewerCertificate' => [
                'CloudFrontDefaultCertificate' => false,
                'ACMCertificateArn'            => $certificate_arn,
                'MinimumProtocolVersion'       => 'TLSv1.1_2016',
                'SSLSupportMethod'             => 'sni-only'
            ],
            'DefaultRootObject' => 'index.html',

            'DefaultCacheBehavior' => [
                'AllowedMethods'       => [
                    'CachedMethods' => [
                        'Items'    => ['HEAD', 'GET'],
                        'Quantity' => 2
                    ],
                    'Items'         => ['HEAD', 'DELETE', 'POST', 'GET', 'OPTIONS', 'PUT', 'PATCH'],
                    'Quantity'      => 7
                ],
                'TargetOriginId'       => $fqdn,
                'ForwardedValues'      => [
                    'QueryString' => true,
                    'Cookies'     => ['Forward' => 'all'],
                    'Headers'     => [
                        'Items'    => [
                            'Accept',
                            'Accept-Language',
                            'Authorization',
                            'CloudFront-Forwarded-Proto',
                            'Host',
                            'Origin',
                            'Referer',
                            'User-agent'
                        ],
                        'Quantity' => 8
                    ]
                ],
                'ViewerProtocolPolicy' => 'redirect-to-https',
                'MaxTTL'               => 0,
                'MinTTL'               => 0,
                'DefaultTTL'           => 0,
                'Compress'             => true,
                'SmoothStreaming'      => false,
                'TrustedSigners'       => [
                    'Enabled'  => false,
                    'Quantity' => 0,
                ]
            ],

            'CacheBehaviors' => [
                'Items'    => [
                    [
                        'PathPattern'          => '*.png',
                        'AllowedMethods'       => [
                            'CachedMethods' => [
                                'Items'    => ['HEAD', 'GET'],
                                'Quantity' => 2,
                            ],
                            'Items'         => ['GET', 'HEAD'],
                            'Quantity'      => 2,
                        ],
                        'TargetOriginId'       => $fqdn,
                        'ForwardedValues'      => [
                            'QueryString' => true,
                            'Cookies'     => ['Forward' => 'none'],
                            'Headers'     => [
                                'Items'    => [
                                    'Accept',
                                    'Accept-Language',
                                    'Authorization',
                                    'CloudFront-Forwarded-Proto',
                                    'Host',
                                    'Origin',
                                    'Referer',
                                    'User-agent'
                                ],
                                'Quantity' => 8
                            ]
                        ],
                        'MaxTTL'               => 31536000,
                        'MinTTL'               => 0,
                        'DefaultTTL'           => 86400,
                        'Compress'             => true,
                        'ViewerProtocolPolicy' => 'redirect-to-https',
                        'TrustedSigners'       => [
                            'Enabled'  => false,
                            'Quantity' => 0
                        ]
                    ],
                    [
                        'PathPattern'          => '*.jpg',
                        'AllowedMethods'       => [
                            'CachedMethods' => [
                                'Items'    => ['HEAD', 'GET'],
                                'Quantity' => 2,
                            ],
                            'Items'         => ['GET', 'HEAD'],
                            'Quantity'      => 2,
                        ],
                        'TargetOriginId'       => $fqdn,
                        'ForwardedValues'      => [
                            'QueryString' => true,
                            'Cookies'     => ['Forward' => 'none'],
                            'Headers'     => [
                                'Items'    => [
                                    'Accept',
                                    'Accept-Language',
                                    'Authorization',
                                    'CloudFront-Forwarded-Proto',
                                    'Host',
                                    'Origin',
                                    'Referer',
                                    'User-agent'
                                ],
                                'Quantity' => 8
                            ]
                        ],
                        'MaxTTL'               => 31536000,
                        'MinTTL'               => 0,
                        'DefaultTTL'           => 86400,
                        'Compress'             => true,
                        'ViewerProtocolPolicy' => 'redirect-to-https',
                        'TrustedSigners'       => [
                            'Enabled'  => false,
                            'Quantity' => 0
                        ]
                    ],
                    [
                        'PathPattern'          => '*.gif',
                        'AllowedMethods'       => [
                            'CachedMethods' => [
                                'Items'    => ['HEAD', 'GET'],
                                'Quantity' => 2,
                            ],
                            'Items'         => ['GET', 'HEAD'],
                            'Quantity'      => 2,
                        ],
                        'TargetOriginId'       => $fqdn,
                        'ForwardedValues'      => [
                            'QueryString' => true,
                            'Cookies'     => ['Forward' => 'none'],
                            'Headers'     => [
                                'Items'    => [
                                    'Accept',
                                    'Accept-Language',
                                    'Authorization',
                                    'CloudFront-Forwarded-Proto',
                                    'Host',
                                    'Origin',
                                    'Referer',
                                    'User-agent'
                                ],
                                'Quantity' => 8
                            ]
                        ],
                        'MaxTTL'               => 31536000,
                        'MinTTL'               => 0,
                        'DefaultTTL'           => 86400,
                        'Compress'             => true,
                        'ViewerProtocolPolicy' => 'redirect-to-https',
                        'TrustedSigners'       => [
                            'Enabled'  => false,
                            'Quantity' => 0
                        ]
                    ],
                    [
                        'PathPattern'          => '*.css',
                        'AllowedMethods'       => [
                            'CachedMethods' => [
                                'Items'    => ['HEAD', 'GET'],
                                'Quantity' => 2,
                            ],
                            'Items'         => ['GET', 'HEAD'],
                            'Quantity'      => 2,
                        ],
                        'TargetOriginId'       => $fqdn,
                        'ForwardedValues'      => [
                            'QueryString' => true,
                            'Cookies'     => ['Forward' => 'none'],
                            'Headers'     => [
                                'Items'    => [
                                    'Accept',
                                    'Accept-Language',
                                    'Authorization',
                                    'CloudFront-Forwarded-Proto',
                                    'Host',
                                    'Origin',
                                    'Referer',
                                    'User-agent'
                                ],
                                'Quantity' => 8
                            ]
                        ],
                        'MaxTTL'               => 31536000,
                        'MinTTL'               => 0,
                        'DefaultTTL'           => 86400,
                        'Compress'             => true,
                        'ViewerProtocolPolicy' => 'redirect-to-https',
                        'TrustedSigners'       => [
                            'Enabled'  => false,
                            'Quantity' => 0
                        ]
                    ],
                    [
                        'PathPattern'          => '*.js',
                        'AllowedMethods'       => [
                            'CachedMethods' => [
                                'Items'    => ['HEAD', 'GET'],
                                'Quantity' => 2,
                            ],
                            'Items'         => ['GET', 'HEAD'],
                            'Quantity'      => 2,
                        ],
                        'TargetOriginId'       => $fqdn,
                        'ForwardedValues'      => [
                            'QueryString' => true,
                            'Cookies'     => ['Forward' => 'none'],
                            'Headers'     => [
                                'Items'    => [
                                    'Accept',
                                    'Accept-Language',
                                    'Authorization',
                                    'CloudFront-Forwarded-Proto',
                                    'Host',
                                    'Origin',
                                    'Referer',
                                    'User-agent'
                                ],
                                'Quantity' => 8
                            ]
                        ],
                        'MaxTTL'               => 31536000,
                        'MinTTL'               => 0,
                        'DefaultTTL'           => 86400,
                        'Compress'             => true,
                        'ViewerProtocolPolicy' => 'redirect-to-https',
                        'TrustedSigners'       => [
                            'Enabled'  => false,
                            'Quantity' => 0
                        ]
                    ],
                ],
                'Quantity' => 5
            ],

            'CustomErrorResponses' => [
                'Items'    => [
                    [
                        'ErrorCachingMinTTL' => 0,
                        'ErrorCode'          => 404,
                        'ResponseCode'       => '404',
                        'ResponsePagePath'   => '/404.html',
                    ],
                ],
                'Quantity' => 1,
            ],
        ]
    ]);
    if ($result['@metadata']['statusCode'] !== 201) {
        print_r($result);
        exit();
    }
    $cloud_front_domain = $result['Distribution']['DomainName'];
    print sprintf('%s CNAME %s', $fqdn, $cloud_front_domain) . PHP_EOL;

} else {
    print 'Exists CloudFront.' . PHP_EOL;
    $result = $cloudFrontClient->getDistribution([
        'Id' => $cloud_front_id
    ]);
    if ($result['@metadata']['statusCode'] !== 200) {
        print_r($result);
        exit();
    }
    $cloud_front_domain = $result['Distribution']['DomainName'];
    print sprintf('%s CNAME %s', $fqdn, $cloud_front_domain) . PHP_EOL;
}
