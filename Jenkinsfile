pipeline {
    agent any

    environment {
        // ── AWS config ──
        AWS_REGION      = "us-east-1"
        AWS_ACCOUNT_ID  = "YOUR-AWS-ACCOUNT-ID"  // replace this
        ECR_REPO        = "blood-donation-app"

        // ── Full ECR image URL ──
        ECR_REGISTRY    = "${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com"
        IMAGE_TAG       = "${BUILD_NUMBER}"  // unique tag per build
        FULL_IMAGE      = "${ECR_REGISTRY}/${ECR_REPO}:${IMAGE_TAG}"

        // ── App config ──
        APP_NAME        = "blood-donation-app"
        DEPLOY_PATH     = "/var/www/html"
        EC2_USER        = "ec2-user"

        // ── DB config — stored as Jenkins credentials ──
        DB_NAME         = "customers"
    }

    stages {

        // ────────────────────────────────────────
        // STAGE 1 — Pull latest code
        // ────────────────────────────────────────
        stage("Checkout") {
            steps {
                echo "Pulling latest code from GitHub..."
                checkout scm
                echo "Code pulled successfully"
            }
        }

        // ────────────────────────────────────────
        // STAGE 2 — Validate PHP syntax
        // ────────────────────────────────────────
               // ────────────────────────────────────────
        // STAGE 3 — Get EC2 private IPs dynamically
        // No hardcoding needed — discovers IPs from AWS
        // ────────────────────────────────────────
        stage("Discover EC2 IPs") {
            steps {
                echo "Finding running EC2 instances..."
                script {
                    def ips = sh(
                        script: """
                            aws ec2 describe-instances \
                              --region ${AWS_REGION} \
                              --filters \
                                "Name=tag:Name,Values=blood-donation-asg-instance" \
                                "Name=instance-state-name,Values=running" \
                              --query "Reservations[].Instances[].PrivateIpAddress" \
                              --output text
                        """,
                        returnStdout: true
                    ).trim().split()

                    if (ips.size() < 1) {
                        error "No running EC2 instances found. Did terraform apply finish?"
                    }

                    env.EC2_HOST_A = ips[0]
                    env.EC2_HOST_B = ips.size() > 1 ? ips[1] : ips[0]

                    echo "EC2 AZ-a IP: ${env.EC2_HOST_A}"
                    echo "EC2 AZ-b IP: ${env.EC2_HOST_B}"
                }
            }
        }

        // ────────────────────────────────────────
        // STAGE 4 — Get RDS endpoint dynamically
        // ────────────────────────────────────────
        stage("Discover RDS Endpoint") {
            steps {
                echo "Finding RDS endpoint..."
                script {
                    env.DB_HOST = sh(
                        script: """
                            aws rds describe-db-instances \
                              --region ${AWS_REGION} \
                              --db-instance-identifier blood-donation-mysql \
                              --query "DBInstances[0].Endpoint.Address" \
                              --output text
                        """,
                        returnStdout: true
                    ).trim()

                    echo "RDS endpoint: ${env.DB_HOST}"
                }
            }
        }

        // ────────────────────────────────────────
        // STAGE 5 — Run DB migration
        // Imports your init.sql into RDS
        // ────────────────────────────────────────
        stage("Database Migration") {
    steps {
        echo "Running database migration..."
        withCredentials([
            string(credentialsId: 'db-password', variable: 'DB_PASSWORD'),
            string(credentialsId: 'db-username', variable: 'DB_USER')
        ]) {
            sh '''
                # Check mysql client exists
                mysql --version || { echo "MySQL client not installed in agent image"; exit 1; }

                # Check if database already has tables
                TABLE_COUNT=$(mysql \
                    -h ${DB_HOST} \
                    -u ${DB_USER} \
                    -p${DB_PASSWORD} \
                    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';" \
                    --skip-column-names 2>/dev/null || echo "0")

                if [ "$TABLE_COUNT" -eq "0" ]; then
                    echo "Running initial database migration..."

                    mysql \
                        -h ${DB_HOST} \
                        -u ${DB_USER} \
                        -p${DB_PASSWORD} \
                        -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME};"

                    mysql \
                        -h ${DB_HOST} \
                        -u ${DB_USER} \
                        -p${DB_PASSWORD} \
                        ${DB_NAME} < database/init.sql

                    echo "Database migration completed successfully"
                else
                    echo "Database already has $TABLE_COUNT tables — skipping migration"
                fi
            '''
        }
    }
}

        // ────────────────────────────────────────
        // STAGE 6 — Create ECR repo if not exists
        // ────────────────────────────────────────
        stage("Setup ECR") {
            steps {
                echo "Setting up ECR repository..."
                sh '''
                    aws ecr describe-repositories \
                        --repository-names ${ECR_REPO} \
                        --region ${AWS_REGION} 2>/dev/null || \
                    aws ecr create-repository \
                        --repository-name ${ECR_REPO} \
                        --region ${AWS_REGION} \
                        --image-scanning-configuration scanOnPush=true
                    echo "ECR repository ready"
                '''
            }
        }

        // ────────────────────────────────────────
        // STAGE 7 — Build Docker image
        // ────────────────────────────────────────
        stage("Build Docker Image") {
            steps {
                echo "Building Docker image..."
                sh '''
                    docker build \
                        -t ${APP_NAME}:${IMAGE_TAG} \
                        -t ${APP_NAME}:latest \
                        .
                    echo "Docker image built: ${APP_NAME}:${IMAGE_TAG}"
                    docker images | grep ${APP_NAME}
                '''
            }
        }

        // ────────────────────────────────────────
        // STAGE 8 — Push image to ECR
        // ────────────────────────────────────────
        stage("Push to ECR") {
            steps {
                echo "Pushing image to ECR..."
                sh '''
                    # Login to ECR
                    aws ecr get-login-password \
                        --region ${AWS_REGION} | \
                    docker login \
                        --username AWS \
                        --password-stdin ${ECR_REGISTRY}

                    # Tag with ECR URL
                    docker tag ${APP_NAME}:${IMAGE_TAG} ${FULL_IMAGE}
                    docker tag ${APP_NAME}:latest \
                        ${ECR_REGISTRY}/${ECR_REPO}:latest

                    # Push both tags
                    docker push ${FULL_IMAGE}
                    docker push ${ECR_REGISTRY}/${ECR_REPO}:latest

                    echo "Image pushed to ECR: ${FULL_IMAGE}"
                '''
            }
        }

        // ────────────────────────────────────────
        // STAGE 9 — Deploy to EC2 AZ-a
        // SSH in and pull Docker image from ECR
        // ────────────────────────────────────────
        stage("Deploy to EC2 AZ-a") {
            steps {
                echo "Deploying to EC2 in AZ-a: ${EC2_HOST_A}"
                withCredentials([
                    sshUserPrivateKey(
                        credentialsId: 'ec2-ssh-key',
                        keyFileVariable: 'SSH_KEY'
                    ),
                    string(credentialsId: 'db-password', variable: 'DB_PASSWORD'),
                    string(credentialsId: 'db-username', variable: 'DB_USER')
                ]) {
                    sh '''
                        ssh -i ${SSH_KEY} \
                            -o StrictHostKeyChecking=no \
                            ${EC2_USER}@${EC2_HOST_A} << ENDSSH

                            set -e

                            echo "Logging into ECR..."
                            aws ecr get-login-password \
                                --region ${AWS_REGION} | \
                            docker login \
                                --username AWS \
                                --password-stdin ${ECR_REGISTRY}

                            echo "Pulling latest image from ECR..."
                            docker pull ${FULL_IMAGE}

                            echo "Stopping old container if running..."
                            docker stop blood-donation-app 2>/dev/null || true
                            docker rm blood-donation-app 2>/dev/null || true

                            echo "Starting new container..."
                            docker run -d \
                                --name blood-donation-app \
                                --restart always \
                                -p 80:80 \
                                -e DB_HOST=${DB_HOST} \
                                -e DB_USER=${DB_USER} \
                                -e DB_PASSWORD=${DB_PASSWORD} \
                                -e DB_NAME=${DB_NAME} \
                                ${FULL_IMAGE}

                            echo "Container started on AZ-a"
                            docker ps | grep blood-donation-app
ENDSSH
                    '''
                }
            }
        }

        // ────────────────────────────────────────
        // STAGE 10 — Health check AZ-a
        // Wait for container to be ready
        // ────────────────────────────────────────
        stage("Health Check AZ-a") {
            steps {
                echo "Waiting for AZ-a to become healthy..."
                sh '''
                    sleep 20
                    RETRIES=5
                    COUNT=0
                    while [ $COUNT -lt $RETRIES ]; do
                        HTTP_CODE=$(curl -s -o /dev/null \
                            -w "%{http_code}" \
                            http://${EC2_HOST_A}/ \
                            --connect-timeout 5 || echo "000")

                        if [ "$HTTP_CODE" = "200" ]; then
                            echo "AZ-a health check passed — HTTP $HTTP_CODE"
                            exit 0
                        fi

                        echo "AZ-a not ready yet (HTTP $HTTP_CODE) — retry $((COUNT+1))/$RETRIES"
                        COUNT=$((COUNT+1))
                        sleep 15
                    done

                    echo "AZ-a health check failed after $RETRIES attempts"
                    exit 1
                '''
            }
        }

        // ────────────────────────────────────────
        // STAGE 11 — Deploy to EC2 AZ-b
        // Same process as AZ-a
        // ────────────────────────────────────────
        stage("Deploy to EC2 AZ-b") {
            steps {
                echo "Deploying to EC2 in AZ-b: ${EC2_HOST_B}"
                withCredentials([
                    sshUserPrivateKey(
                        credentialsId: 'ec2-ssh-key',
                        keyFileVariable: 'SSH_KEY'
                    ),
                    string(credentialsId: 'db-password', variable: 'DB_PASSWORD'),
                    string(credentialsId: 'db-username', variable: 'DB_USER')
                ]) {
                    sh '''
                        ssh -i ${SSH_KEY} \
                            -o StrictHostKeyChecking=no \
                            ${EC2_USER}@${EC2_HOST_B} << ENDSSH

                            set -e

                            echo "Logging into ECR..."
                            aws ecr get-login-password \
                                --region ${AWS_REGION} | \
                            docker login \
                                --username AWS \
                                --password-stdin ${ECR_REGISTRY}

                            echo "Pulling latest image from ECR..."
                            docker pull ${FULL_IMAGE}

                            echo "Stopping old container if running..."
                            docker stop blood-donation-app 2>/dev/null || true
                            docker rm blood-donation-app 2>/dev/null || true

                            echo "Starting new container..."
                            docker run -d \
                                --name blood-donation-app \
                                --restart always \
                                -p 80:80 \
                                -e DB_HOST=${DB_HOST} \
                                -e DB_USER=${DB_USER} \
                                -e DB_PASSWORD=${DB_PASSWORD} \
                                -e DB_NAME=${DB_NAME} \
                                ${FULL_IMAGE}

                            echo "Container started on AZ-b"
                            docker ps | grep blood-donation-app
ENDSSH
                    '''
                }
            }
        }

        // ────────────────────────────────────────
        // STAGE 12 — Health check AZ-b
        // ────────────────────────────────────────
        stage("Health Check AZ-b") {
            steps {
                echo "Waiting for AZ-b to become healthy..."
                sh '''
                    sleep 20
                    RETRIES=5
                    COUNT=0
                    while [ $COUNT -lt $RETRIES ]; do
                        HTTP_CODE=$(curl -s -o /dev/null \
                            -w "%{http_code}" \
                            http://${EC2_HOST_B}/ \
                            --connect-timeout 5 || echo "000")

                        if [ "$HTTP_CODE" = "200" ]; then
                            echo "AZ-b health check passed — HTTP $HTTP_CODE"
                            exit 0
                        fi

                        echo "AZ-b not ready yet (HTTP $HTTP_CODE) — retry $((COUNT+1))/$RETRIES"
                        COUNT=$((COUNT+1))
                        sleep 15
                    done

                    echo "AZ-b health check failed after $RETRIES attempts"
                    exit 1
                '''
            }
        }

        // ────────────────────────────────────────
        // STAGE 13 — Final ALB check
        // Confirms app is reachable from outside
        // ────────────────────────────────────────
        stage("Final ALB Health Check") {
            steps {
                echo "Checking app via ALB..."
                script {
                    env.ALB_DNS = sh(
                        script: """
                            aws elbv2 describe-load-balancers \
                              --region ${AWS_REGION} \
                              --names blood-donation-alb \
                              --query "LoadBalancers[0].DNSName" \
                              --output text
                        """,
                        returnStdout: true
                    ).trim()

                    echo "ALB DNS: ${env.ALB_DNS}"
                }
                sh '''
                    sleep 30
                    HTTP_CODE=$(curl -s -o /dev/null \
                        -w "%{http_code}" \
                        http://${ALB_DNS}/ \
                        --connect-timeout 10 || echo "000")

                    echo "ALB response: HTTP $HTTP_CODE"

                    if [ "$HTTP_CODE" = "200" ]; then
                        echo "App is live at http://${ALB_DNS}"
                    else
                        echo "ALB returned HTTP $HTTP_CODE"
                        echo "Check ALB target group health in AWS Console"
                    fi
                '''
            }
        }
    }

    // ────────────────────────────────────────
    // POST — runs after all stages complete
    // ────────────────────────────────────────
    post {
        success {
            echo """
            ============================================
            DEPLOYMENT SUCCESSFUL
            App: ${env.APP_NAME}
            Build: ${env.BUILD_NUMBER}
            Image: ${env.FULL_IMAGE}
            App URL: http://${env.ALB_DNS}
            ============================================
            """
        }

        failure {
            echo """
            ============================================
            DEPLOYMENT FAILED
            App: ${env.APP_NAME}
            Build: ${env.BUILD_NUMBER}
            Check console output above for the error
            ============================================
            """
        }

        always {
            echo "Cleaning up Docker images on Jenkins..."
            sh '''
                docker rmi ${FULL_IMAGE} 2>/dev/null || true
                docker rmi ${APP_NAME}:${IMAGE_TAG} 2>/dev/null || true
                docker system prune -f 2>/dev/null || true
            '''
            cleanWs()
        }
    }
}
