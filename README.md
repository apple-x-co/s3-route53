# s3-route53

## cloudfront-s3.php

### AWS Resources

以下のリソースを作成

* S3 Bucket
* Certificate
* CloudFront

### Domain service

ドメイン管理画面で以下を登録

* Certificate の DNS認証
`xxx.your-domain. 600 IN CNAME zzz.yyy.acm-validations.aws.`
* CloudFront への転送
`xxx.your-domain. 600 IN CNAME vvv.cloudfront.net.`

## route53-cloudfront-s3.php

### AWS Resources

以下のリソースを作成

* Route53 Hosted zone
* S3 Bucket
* Certificate
* CloudFront

### Domain service

ドメイン管理画面で以下を登録

* NSレコード  
`xxx.your-domain. 86400 IN NS ns-aaa.awsdns-11.org.`  
`xxx.your-domain. 86400 IN NS ns-bbb.awsdns-22.co.uk.`  
`xxx.your-domain. 86400 IN NS ns-ccc.awsdns-33.net.`  
`xxx.your-domain. 86400 IN NS ns-ddd.awsdns-44.com..`  
