#!/usr/bin/env bash
set -Eeuo pipefail
export AWS_PAGER=""

instance=${1:-}
region=${2:-}
if [[ $# != 2 || ! $instance =~ ^i-[0-9a-f]{17}$ || ! $region =~ ^[a-z]{2}-[a-z]+-[0-9]+$ ]]; then
    echo 'Usage: bash setup-aws.sh INSTANCE_ID REGION' >&2
    exit 2
fi
export AWS_REGION="$region" AWS_DEFAULT_REGION="$region"
account=$(aws sts get-caller-identity --query Account --output text)
server=$(aws ec2 describe-instances --instance-ids "$instance" \
    --query 'Reservations[0].Instances[0]' --output json)
if ! jq -e '.State.Name == "running" and any(.Tags[]?; .Key == "Name" and .Value == "hatid-api")' <<< "$server" >/dev/null; then
    echo 'Expected the running Hatid API instance. Confirm the account and region.' >&2
    exit 1
fi
existing_profile=$(jq -r '.IamInstanceProfile.Arn // ""' <<< "$server")
if [[ -n $existing_profile && $existing_profile != "arn:aws:iam::$account:instance-profile/HatidApiHostProfile" ]]; then
    echo 'The instance already uses a different IAM profile.' >&2
    exit 1
fi
vpc=$(jq -er .VpcId <<< "$server")
stack=hatid-api-deploy
provider="arn:aws:iam::$account:oidc-provider/token.actions.githubusercontent.com"
if ! existing_provider=$(aws cloudformation describe-stacks --stack-name "$stack" \
    --query 'Stacks[0].Parameters[?ParameterKey==`ProviderArn`].ParameterValue | [0]' --output text 2>/dev/null); then
    existing_provider=New
    if aws iam get-open-id-connect-provider --open-id-connect-provider-arn "$provider" \
        --query 'contains(ClientIDList, `"sts.amazonaws.com"`)' --output text 2>/dev/null | grep -Fxq True; then
        existing_provider=$provider
    fi
fi

template=$(mktemp)
trap 'rm -f -- "$template"' EXIT
cat > "$template" <<'CLOUDFORMATION'
AWSTemplateFormatVersion: '2010-09-09'
Description: Hatid API main deployment to one existing EC2 instance
Parameters:
  InstanceId:
    Type: AWS::EC2::Instance::Id
  VpcId:
    Type: AWS::EC2::VPC::Id
  ProviderArn:
    Type: String
Conditions:
  CreateProvider: !Equals [!Ref ProviderArn, New]
Resources:
  GitHubProvider:
    Type: AWS::IAM::OIDCProvider
    Condition: CreateProvider
    Properties:
      Url: https://token.actions.githubusercontent.com
      ClientIdList: [sts.amazonaws.com]
  ReleaseBucket:
    Type: AWS::S3::Bucket
    Properties:
      PublicAccessBlockConfiguration:
        BlockPublicAcls: true
        BlockPublicPolicy: true
        IgnorePublicAcls: true
        RestrictPublicBuckets: true
      BucketEncryption:
        ServerSideEncryptionConfiguration:
          - ServerSideEncryptionByDefault:
              SSEAlgorithm: AES256
      LifecycleConfiguration:
        Rules:
          - Id: ExpireOldReleases
            Prefix: releases/
            Status: Enabled
            ExpirationInDays: 14
  HostRole:
    Type: AWS::IAM::Role
    Properties:
      AssumeRolePolicyDocument:
        Version: '2012-10-17'
        Statement:
          - Effect: Allow
            Principal: {Service: ec2.amazonaws.com}
            Action: sts:AssumeRole
      ManagedPolicyArns: ['arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore']
      Policies:
        - PolicyName: ReadHatidRelease
          PolicyDocument:
            Version: '2012-10-17'
            Statement:
              - Effect: Deny
                Action: ['ssm:GetParameter', 'ssm:GetParameters', 'ssm:GetParametersByPath']
                Resource: '*'
              - Effect: Allow
                Action: s3:GetObject
                Resource: !Sub '${ReleaseBucket.Arn}/releases/*'
  HostProfile:
    Type: AWS::IAM::InstanceProfile
    Properties:
      InstanceProfileName: HatidApiHostProfile
      Roles: [!Ref HostRole]
  ReleaseCommand:
    Type: AWS::SSM::Document
    Properties:
      Name: HatidApiRelease
      DocumentType: Command
      UpdateMethod: NewVersion
      Content:
        schemaVersion: '2.2'
        description: Activate a checksummed Hatid API release
        parameters:
          Commit:
            type: String
            allowedPattern: '^[a-f0-9]{40}$'
            interpolationType: ENV_VAR
          Digest:
            type: String
            allowedPattern: '^[a-f0-9]{64}$'
            interpolationType: ENV_VAR
        mainSteps:
          - action: aws:runShellScript
            name: activate
            inputs:
              timeoutSeconds: '540'
              runCommand:
                - 'exec /usr/local/sbin/hatid-release "$SSM_Commit" "$SSM_Digest"'
  GitHubRole:
    Type: AWS::IAM::Role
    Properties:
      AssumeRolePolicyDocument:
        Version: '2012-10-17'
        Statement:
          - Effect: Allow
            Principal:
              Federated: !If [CreateProvider, !Ref GitHubProvider, !Ref ProviderArn]
            Action: sts:AssumeRoleWithWebIdentity
            Condition:
              StringEquals:
                token.actions.githubusercontent.com:aud: sts.amazonaws.com
                token.actions.githubusercontent.com:sub: 'repo:novel16@116881008/hatid-api@1441840200:ref:refs/heads/main'
      Policies:
        - PolicyName: PublishHatidRelease
          PolicyDocument:
            Version: '2012-10-17'
            Statement:
              - Effect: Allow
                Action: s3:PutObject
                Resource: !Sub '${ReleaseBucket.Arn}/releases/*'
              - Effect: Allow
                Action: ssm:SendCommand
                Resource:
                  - !Sub 'arn:aws:ec2:${AWS::Region}:${AWS::AccountId}:instance/${InstanceId}'
                  - !Sub 'arn:aws:ssm:${AWS::Region}:${AWS::AccountId}:document/${ReleaseCommand}'
              - Effect: Allow
                Action: ssm:GetCommandInvocation
                Resource: '*'
  WebSecurityGroup:
    Type: AWS::EC2::SecurityGroup
    Properties:
      GroupDescription: Public Hatid HTTPS and ACME HTTP validation
      VpcId: !Ref VpcId
      SecurityGroupIngress:
        - {IpProtocol: tcp, FromPort: 80, ToPort: 80, CidrIp: '0.0.0.0/0'}
        - {IpProtocol: tcp, FromPort: 443, ToPort: 443, CidrIp: '0.0.0.0/0'}
Outputs:
  Bucket:
    Value: !Ref ReleaseBucket
  DeployRole:
    Value: !GetAtt GitHubRole.Arn
  WebGroup:
    Value: !GetAtt WebSecurityGroup.GroupId
CLOUDFORMATION

aws cloudformation validate-template --template-body "file://$template" >/dev/null
aws cloudformation deploy --template-file "$template" --stack-name "$stack" \
    --capabilities CAPABILITY_IAM CAPABILITY_NAMED_IAM --no-fail-on-empty-changeset \
    --parameter-overrides "InstanceId=$instance" "VpcId=$vpc" "ProviderArn=$existing_provider"
outputs=$(aws cloudformation describe-stacks --stack-name "$stack" \
    --query 'Stacks[0].Outputs' --output json |
    jq -c 'map({key: .OutputKey, value: .OutputValue}) | from_entries')
group=$(jq -er .WebGroup <<< "$outputs")

if [[ -z $existing_profile ]]; then
    aws ec2 associate-iam-instance-profile --instance-id "$instance" \
        --iam-instance-profile Name=HatidApiHostProfile >/dev/null
fi

current_groups=$(aws ec2 describe-instances --instance-ids "$instance" \
    --query 'Reservations[0].Instances[0].SecurityGroups[].GroupId' --output json)
mapfile -t groups < <(jq -er '.[]' <<< "$current_groups")
(( ${#groups[@]} > 0 ))
if ! printf '%s\n' "${groups[@]}" | grep -Fxq "$group"; then
    aws ec2 modify-instance-attribute --instance-id "$instance" --groups "${groups[@]}" "$group"
fi

ip=$(aws ec2 describe-addresses --filters "Name=instance-id,Values=$instance" \
    --query 'Addresses[0].PublicIp' --output text)
if [[ $ip == None ]]; then
    address=$(aws ec2 describe-addresses --filters "Name=tag:HatidInstance,Values=$instance" \
        --query 'Addresses' --output json |
        jq -c '[.[] | select(.AssociationId == null)] | first // null')
    if [[ $address == null ]]; then
        address=$(aws ec2 allocate-address --domain vpc \
            --tag-specifications "ResourceType=elastic-ip,Tags=[{Key=Name,Value=hatid-api},{Key=HatidInstance,Value=$instance}]" \
            --output json)
    fi
    allocation=$(jq -er .AllocationId <<< "$address")
    aws ec2 associate-address --instance-id "$instance" --allocation-id "$allocation" \
        --no-allow-reassociation >/dev/null
    ip=$(jq -er .PublicIp <<< "$address")
fi

jq -cn --arg region "$region" --arg instance "$instance" --arg origin "https://$ip" \
    --arg bucket "$(jq -er .Bucket <<< "$outputs")" --arg role "$(jq -er .DeployRole <<< "$outputs")" \
    '{region: $region, instance: $instance, origin: $origin, bucket: $bucket, role: $role}'
