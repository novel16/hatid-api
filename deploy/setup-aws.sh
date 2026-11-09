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
server=$(aws ec2 describe-instances --instance-ids "$instance" \
    --query 'Reservations[0].Instances[0]' --output json)
if ! jq -e '.State.Name == "running" and any(.Tags[]?; .Key == "Name" and .Value == "hatid-api")' <<< "$server" >/dev/null; then
    echo 'Expected the running Hatid API instance. Confirm the account and region.' >&2
    exit 1
fi

vpc=$(jq -er .VpcId <<< "$server")
web_group=$(aws ec2 describe-security-groups \
    --filters "Name=vpc-id,Values=$vpc" 'Name=group-name,Values=hatid-api-web' \
    --query 'SecurityGroups[0]' --output json)
if [[ $web_group == null ]]; then
    group=$(aws ec2 create-security-group --group-name hatid-api-web \
        --description 'Public Hatid HTTPS and ACME validation' --vpc-id "$vpc" \
        --tag-specifications "ResourceType=security-group,Tags=[{Key=Name,Value=hatid-api-web},{Key=HatidInstance,Value=$instance}]" \
        --query GroupId --output text)
else
    jq -e --arg instance "$instance" \
        'any(.Tags[]?; .Key == "HatidInstance" and .Value == $instance)' <<< "$web_group" >/dev/null
    group=$(jq -er .GroupId <<< "$web_group")
fi

for port in 80 443; do
    if ! jq -e --argjson port "$port" \
        'any(.IpPermissions[]?; .IpProtocol == "tcp" and .FromPort == $port and .ToPort == $port and any(.IpRanges[]?; .CidrIp == "0.0.0.0/0"))' \
        <<< "$web_group" >/dev/null; then
        aws ec2 authorize-security-group-ingress --group-id "$group" \
            --protocol tcp --port "$port" --cidr 0.0.0.0/0 >/dev/null
    fi
done

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
    '{region: $region, instance: $instance, origin: $origin}'
