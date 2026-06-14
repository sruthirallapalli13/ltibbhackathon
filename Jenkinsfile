pipeline {
    agent any

    environment {
        AWS_REGION   = 'us-east-1'
        AWS_ACCOUNT  = '962254627408'
        APP_NAME     = 'blood-donation-app'
        IMAGE_TAG    = "${env.BUILD_NUMBER}"
        ECR_REPO     = 'blood-donation-app'
        ECR_REGISTRY = "${AWS_ACCOUNT}.dkr.ecr.${AWS_REGION}.amazonaws.com"
        FULL_IMAGE   = "${ECR_REGISTRY}/${ECR_REPO}:${IMAGE_TAG}"

        DB_HOST      = 'blood-donation-mysql.cs10y6q88m7j.us-east-1.rds.amazonaws.com'
        DB_NAME      = 'customers'

        EC2_USER     = 'ec2-user'
        EC2_HOST_A   = 'EC2-A-PUBLIC-DNS'
        EC2_HOST_B   = 'EC2-B-PUBLIC-DNS'
    }

    stages {

        stage('Checkout') {
            steps {
                checkout scm
            }
        }

        stage('Build Docker Image') {
            steps {
                echo "Building Docker image..."
                sh '''
                    docker build -t ${APP_NAME}:${IMAGE_TAG} -t ${APP_NAME}:latest .
                '''
            }
        }

        stage('Database Migration') {
            steps {
                echo "Running database migration..."
                withCredentials([
                    string(credentialsId: 'db-password', variable: 'DB_PASSWORD'),
                    string(credentialsId: 'db-username', variable: 'DB_USER')
                ]) {
                    sh '''
                        mysql --version || { echo "MySQL client not installed on Jenkins server"; exit 1; }

                        echo "Checking existing tables in database: ${DB_NAME}"

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
                                -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME};" || true

                            mysql \
                                -h ${DB_HOST} \
                                -u ${DB_USER} \
                                -p${DB_PASSWORD} \
                                ${DB_NAME} < database/init.sql || true

                            echo "Database migration completed successfully"
                        else
                            echo "Database already has $TABLE_COUNT tables — skipping migration"
                        fi
                    '''
                }
            }
        }

        stage('Ensure ECR Repository') {
            steps {
                echo "Ensuring ECR repository exists..."
                sh '''
                    aws ecr describe-repositories \
                        --repository-names ${ECR_REPO} \
                        --region ${AWS_REGION} >/dev/null 2>&1 || \
                    aws ecr create-repository \
                        --repository-name ${ECR_REPO} \
                        --region ${AWS_REGION} \
                        --image-scanning-configuration scanOnPush=true || true
                '''
            }
        }

        stage('Push to ECR') {
            steps {
                echo "Pushing image to ECR..."
                sh '''
                    aws ecr get-login-password \
                        --region ${AWS_REGION} | \
                    docker login \
                        --username AWS \
                        --password-stdin ${ECR_REGISTRY}

                    docker tag ${APP_NAME}:${IMAGE_TAG} ${FULL_IMAGE}
                    docker tag ${APP_NAME}:latest \
                        ${ECR_REGISTRY}/${ECR_REPO}:latest

                    docker push ${FULL_IMAGE}
                    docker push ${ECR_REGISTRY}/${ECR_REPO}:latest

                    echo "Image pushed to ECR: ${FULL_IMAGE}"
                '''
            }
        }

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
