#!/bin/bash

# Setup script for Hostinger resume downloader
# Run this via SSH on your Hostinger server

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}Hostinger Resume Downloader Setup${NC}"
echo -e "${BLUE}========================================${NC}\n"

# Set variables
PROJECT_DIR="~/domains/jobstek.norloxsolutionscrm.com/public_html"
PYTHON_SCRIPT="$PROJECT_DIR/http_downloader_hostinger.py"
STORAGE_DIR="$PROJECT_DIR/storage/resumes"
LOGS_DIR="$PROJECT_DIR/storage/logs/resume_downloader"

# Create necessary directories
echo -e "${YELLOW}Creating directories...${NC}"
mkdir -p "$STORAGE_DIR"
mkdir -p "$LOGS_DIR"

# Set permissions
echo -e "${YELLOW}Setting permissions...${NC}"
chmod 755 "$PYTHON_SCRIPT"
chmod 775 "$STORAGE_DIR"
chmod 775 "$LOGS_DIR"

# Display cron setup options
echo -e "\n${BLUE}Cron Setup Options:${NC}\n"
echo "Choose how often you want to run the resume downloader:"
echo ""
echo "1) Every 30 minutes (48 times/day) - AGGRESSIVE"
echo "2) Every hour (24 times/day) - STANDARD"
echo "3) Every 2 hours (12 times/day) - MODERATE"
echo "4) Every 4 hours (6 times/day) - CONSERVATIVE"
echo "5) Once per day at 2 AM"
echo "6) View current cron jobs"
echo "7) Exit without changes"
echo ""

read -p "Select option (1-7): " option

case $option in
    1)
        CRON_EXPRESSION="*/30 * * * * /usr/bin/python3 $PYTHON_SCRIPT >> $LOGS_DIR/cron_exec.log 2>&1"
        DESCRIPTION="Every 30 minutes"
        ;;
    2)
        CRON_EXPRESSION="0 * * * * /usr/bin/python3 $PYTHON_SCRIPT >> $LOGS_DIR/cron_exec.log 2>&1"
        DESCRIPTION="Every hour"
        ;;
    3)
        CRON_EXPRESSION="0 */2 * * * /usr/bin/python3 $PYTHON_SCRIPT >> $LOGS_DIR/cron_exec.log 2>&1"
        DESCRIPTION="Every 2 hours"
        ;;
    4)
        CRON_EXPRESSION="0 */4 * * * /usr/bin/python3 $PYTHON_SCRIPT >> $LOGS_DIR/cron_exec.log 2>&1"
        DESCRIPTION="Every 4 hours"
        ;;
    5)
        CRON_EXPRESSION="0 2 * * * /usr/bin/python3 $PYTHON_SCRIPT >> $LOGS_DIR/cron_exec.log 2>&1"
        DESCRIPTION="Daily at 2 AM UTC"
        ;;
    6)
        echo -e "\n${BLUE}Current cron jobs:${NC}\n"
        crontab -l 2>/dev/null || echo "No cron jobs configured"
        exit 0
        ;;
    7)
        echo -e "${YELLOW}Setup cancelled.${NC}"
        exit 0
        ;;
    *)
        echo -e "${RED}Invalid option!${NC}"
        exit 1
        ;;
esac

# Add cron job
echo -e "\n${YELLOW}Adding cron job...${NC}"
(crontab -l 2>/dev/null; echo "$CRON_EXPRESSION") | crontab - 2>/dev/null

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Cron job added successfully!${NC}"
    echo -e "  Schedule: ${YELLOW}$DESCRIPTION${NC}"
    echo -e "  Expression: ${YELLOW}$CRON_EXPRESSION${NC}"
else
    echo -e "${RED}✗ Failed to add cron job${NC}"
    exit 1
fi

# Show setup summary
echo -e "\n${BLUE}========================================${NC}"
echo -e "${BLUE}Setup Summary${NC}"
echo -e "${BLUE}========================================${NC}"
echo -e "Project Directory: ${YELLOW}$PROJECT_DIR${NC}"
echo -e "Python Script: ${YELLOW}$PYTHON_SCRIPT${NC}"
echo -e "Storage Directory: ${YELLOW}$STORAGE_DIR${NC}"
echo -e "Logs Directory: ${YELLOW}$LOGS_DIR${NC}"
echo ""
echo -e "Schedule: ${GREEN}$DESCRIPTION${NC}"
echo -e "Cron Expression: ${GREEN}$CRON_EXPRESSION${NC}"
echo ""
echo -e "To view logs:"
echo -e "  ${YELLOW}tail -f $LOGS_DIR/downloader_*.log${NC}"
echo ""
echo -e "To view cron status:"
echo -e "  ${YELLOW}cat $LOGS_DIR/cron_status.log${NC}"
echo ""
echo -e "To list all cron jobs:"
echo -e "  ${YELLOW}crontab -l${NC}"
echo ""
echo -e "To remove this cron job, use:"
echo -e "  ${YELLOW}crontab -e${NC}"
echo -e "  Then delete the line with $PYTHON_SCRIPT"
echo ""
echo -e "${GREEN}✓ Setup complete!${NC}\n"
