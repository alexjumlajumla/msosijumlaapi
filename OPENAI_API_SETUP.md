# OpenAI API Setup Guide

This project uses OpenAI's API for various AI-powered features including voice processing and food recommendations. Follow this guide to set up your API key properly.

## Getting an API Key

1. Create an account on [OpenAI's platform](https://platform.openai.com/signup)
2. Navigate to [API Keys](https://platform.openai.com/account/api-keys)
3. Click "Create new secret key"
4. Copy your new API key (you won't be able to see it again!)

## Configuring Your Environment

1. In your project root directory, make sure you have a `.env` or `.env.local` file
2. Add the following line to your file:
   ```
   OPENAI_API_KEY=your_api_key_here
   ```
3. Replace `your_api_key_here` with the API key you copied from OpenAI
4. After updating your environment file, clear the config cache with:
   ```
   php artisan config:clear
   ```

## Model Compatibility

This application uses the `orhanerday/open-ai` PHP library (version 3.5), which has specific model compatibility requirements:

- **Compatible Models:** This version works with `gpt-3.5-turbo-instruct` using the `completion()` method
- **Incompatible Models:** The regular `gpt-3.5-turbo` model requires methods not available in this library version

If you encounter errors about "Failed to process OpenAI request", it may be because the application is trying to use an incompatible model with the wrong method. The application has been updated to use compatible models.

## Troubleshooting Common Issues

### Quota Exceeded Error

If you see: `API key validation failed: You exceeded your current quota, please check your plan and billing details.`

**Solution:**
1. Check your account's [billing status](https://platform.openai.com/account/billing)
2. Add a payment method if you don't have one
3. Consider upgrading your plan for higher usage limits
4. Check if you have exceeded your monthly budget

### Invalid API Key Error

If you see: `API key validation failed: Incorrect API key provided`

**Solution:**
1. Ensure you've copied the full API key correctly
2. Check for extra spaces or newlines in your key
3. Verify the key format - it should start with `sk-` (older keys) or `sk-proj-` (newer project-based keys)
4. Try generating a new API key
5. Make sure you're using the key in the correct environment where it was created

### API Key Format

OpenAI has two types of API key formats:
- Regular keys start with `sk-` (e.g., `sk-ABC123...`)
- Project-based keys start with `sk-proj-` (e.g., `sk-proj-ABC123...`)

Both types should work with our API client, but make sure you're using the exact format without any modifications.

### Configuration Cache Issues

If you've updated your `.env` or `.env.local` file but the application is still not recognizing your API key:

1. Clear the Laravel configuration cache:
   ```
   php artisan config:clear
   ```
2. Restart your web server or application
3. If using Laravel Sail/Docker:
   ```
   ./vendor/bin/sail down
   ./vendor/bin/sail up -d
   ```

## Testing Your API Key

You can test if your API key is working properly by:

1. In your browser, navigate to: `/api/test-openai-key` (this will use the key in your .env file)
2. Or provide a key directly: `/api/test-openai-key?api_key=sk-YOUR_KEY_HERE`

A successful response means your OpenAI integration is working correctly. 