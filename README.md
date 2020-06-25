# s3-route53

## AWS Resources

以下のリソースを作成

* S3 Bucket
* Certificate
* CloudFront

## Domain service

ドメイン管理画面で以下を登録

* Certificate の DNS認証
`xxx.your-domain. 600 IN CNAME zzz.yyy.acm-validations.aws.`
* CloudFront への転送
`xxx.your-domain. 600 IN CNAME vvv.cloudfront.net.`
