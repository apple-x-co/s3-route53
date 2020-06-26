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

$route53Client = $aws->createRoute53();
$s3client = $aws->createS3();
$acmClient = $aws->createAcm([
    'region' => 'us-east-1'
]);
$cloudFrontClient = $aws->createCloudFront();

$fqdn = getenv('SITE_FQDN');

// Route53
$route53_zone_id = getenv('AWS_ROUTE53_ZONE_ID');
$name_servers = null;
if ($route53_zone_id === '') {
//    $result = $route53Client->listHostedZonesByName([
//        'DNSName'  => $fqdn,
//        'MaxItems' => 1
//    ]);
//    if ($result['@metadata']['statusCode'] !== 200) {
//        print_r($result);
//        exit();
//    }

    print 'Create Route53 hosted zone.' . PHP_EOL;
    $result = $route53Client->createHostedZone([
        'Name'             => $fqdn,
        'CallerReference'  => '1234567', // ゾーン作成毎に一意になる任意の文字列。ゾーン削除後も有効なので常に変える。
        'HostedZoneConfig' => [
            'Comment'     => sprintf('zone for %s', $fqdn),
            'PrivateZone' => false,
        ]
    ]);
    if ($result['@metadata']['statusCode'] !== 201) {
        print_r($result);
        exit();
    }

    $route53_zone_id = $result['HostedZone']['Id'];
    $name_servers = $result['DelegationSet']['NameServers'];

    print sprintf('zone id : %s', $route53_zone_id) . PHP_EOL;

    print 'name servers : ' . PHP_EOL;
    foreach ($name_servers as $name_server) {
        print $name_server . PHP_EOL;
    }

    print 'Please setting zone_id to .env' . PHP_EOL;
    exit();
}

print 'Exists Route53 hosted zone.' . PHP_EOL;
$result = $route53Client->getHostedZone([
    'Id' => $route53_zone_id
]);
if ($result['@metadata']['statusCode'] !== 200) {
    print_r($result);
    exit();
}

$name_servers = $result['DelegationSet']['NameServers'];
print sprintf('zone id : %s', $route53_zone_id) . PHP_EOL;

print 'name servers : ' . PHP_EOL;
foreach ($name_servers as $name_server) {
    print '- ' . $name_server . PHP_EOL;
}


// check dns
$dns = dns_get_record($fqdn, DNS_NS);
if (empty($dns)) {
    print 'Please NS record.' . PHP_EOL;
    exit();
}

$dns_targets = [];
foreach ($dns as $item) {
    $dns_targets[] = $item['target'];
}

if (! empty(array_diff($name_servers, $dns_targets))) {
    print 'Please check NS record.' . PHP_EOL;
    print 'name servers : ' . PHP_EOL;
    foreach ($name_servers as $name_server) {
        print '- ' . $name_server . PHP_EOL;
    }
    exit();
}


// S3
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


// Certificate
$certificate_arn = getenv('AWS_CERTIFICATE_ARN');
if ($certificate_arn === '') {
    print 'Create certificate.' . PHP_EOL;
    $result = $acmClient->requestCertificate([
        'DomainName'       => $fqdn,
        'IdempotencyToken' => '12345', // 証明書作成リクエストを識別するトークン。同じものを使用するとリクエストが重複しない
        'Options'          => [
            'CertificateTransparencyLoggingPreference' => 'DISABLED',
        ],
        'ValidationMethod' => 'DNS',
    ]);
    if ($result['@metadata']['statusCode'] !== 200) {
        print_r($result);
        exit();
    }

    $certificate_arn = $result['CertificateArn'];
    print sprintf('certificate arn : %s', $certificate_arn) . PHP_EOL;

    sleep(10);
    $result = $acmClient->describeCertificate([
        'CertificateArn' => $certificate_arn
    ]);
    if ($result['@metadata']['statusCode'] !== 200) {
        print_r($result);
        exit();
    }

    print 'Create validation record.' . PHP_EOL;
    $validation_dns_record = $result['Certificate']['DomainValidationOptions'][0]['ResourceRecord'];
    print sprintf('%s %s %s',
            $validation_dns_record['Name'],
            $validation_dns_record['Type'],
            $validation_dns_record['Value']) . PHP_EOL;

    $route53Client->changeResourceRecordSets([
        'HostedZoneId' => $route53_zone_id,
        'ChangeBatch'  => [
            'Changes' => [
                [
                    'Action'            => 'CREATE',
                    'ResourceRecordSet' => [
                        'Name'            => $validation_dns_record['Name'],
                        'Type'            => $validation_dns_record['Type'],
                        'TTL'             => 600,
                        'ResourceRecords' => [
                            [
                                'Value' => $validation_dns_record['Value']
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ]);

    print 'Please wait.' . PHP_EOL;
    exit();
}

print 'Exists certificate.' . PHP_EOL;
print sprintf('certificate arn : %s', $certificate_arn) . PHP_EOL;
$result = $acmClient->describeCertificate([
    'CertificateArn' => $certificate_arn
]);
if ($result['@metadata']['statusCode'] !== 200) {
    print_r($result);
    exit();
}

$certificate_status = $result['Certificate']['Status'];

if ($certificate_status !== 'ISSUED') {
    print 'Please wait.' . PHP_EOL;
    exit();
}

// CloudFront
$cloud_front_id = getenv('AWC_CLOUD_FRONT_ID');
if ($cloud_front_id === '') {
    print 'Distribution CloudFront.' . PHP_EOL;
    $result = $cloudFrontClient->createDistribution([
        'DistributionConfig' => [
            'CallerReference' => '1234567', // CloudFront作成リクエストを識別するトークン。同じものを使用するとリクエストが重複しない

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

    $cloud_front_id = $result['Distribution']['Id'];
    print sprintf('cloud front id : %s', $cloud_front_id) . PHP_EOL;

    $cloud_front_domain = $result['Distribution']['DomainName'];
    print sprintf('%s CNAME %s', $fqdn, $cloud_front_domain) . PHP_EOL;

    $route53Client->changeResourceRecordSets([
        'HostedZoneId' => $route53_zone_id,
        'ChangeBatch'  => [
            'Changes' => [
                [
                    'Action'            => 'CREATE',
                    'ResourceRecordSet' => [
                        'Name' => $fqdn,
                        'Type' => 'A',
                        'AliasTarget' => [
                            'DNSName' => 'd3oh9udgje3wu2.cloudfront.net',
                            'EvaluateTargetHealth' => false,
                            'HostedZoneId' => 'Z2FDTNDATAQYW2' // 固定
                        ]
                    ]
                ]
            ]
        ]
    ]);
}
