# Voice Order System

## Overview

The Voice Order System is an advanced feature that allows users to place food orders using voice commands. The system utilizes speech-to-text technology, AI-powered natural language processing, and a recommendation engine to understand customer preferences and provide relevant product suggestions.

## System Architecture

The Voice Order System is composed of several interconnected components:

1. **Voice Transcription Service** - Converts spoken audio to text using Google Cloud Speech-to-Text API
2. **AI Order Processing Service** - Analyzes the transcribed text to understand user intent and extract order details
3. **Food Intelligence Service** - Filters and recommends products based on the processed order intent
4. **Frontend Voice Interface** - Captures user's voice and displays recommendations

## Requirements and Setup

### Prerequisites

1. Google Cloud Account with Speech-to-Text API enabled
2. OpenAI API account with valid API key
3. PHP 8.0+ with Laravel 8+
4. Composer for PHP dependencies
5. Web server with HTTPS support (for microphone access)

### Google Cloud Setup

1. Create a Google Cloud Project:
   - Go to the [Google Cloud Console](https://console.cloud.google.com/)
   - Create a new project (e.g., "MsosiVoiceOrder")

2. Enable the Speech-to-Text API:
   - Navigate to "APIs & Services" > "Library"
   - Search for and enable "Speech-to-Text API"

3. Create Service Account Credentials:
   - Go to "APIs & Services" > "Credentials"
   - Create a service account with appropriate permissions
   - Generate and download a JSON key file

4. Configure Environment Variables:
   ```
   GOOGLE_APPLICATION_CREDENTIALS="/absolute/path/to/your-credentials-file.json"
   GOOGLE_CLOUD_PROJECT_ID="your-project-id"
   ```

### OpenAI API Setup

1. Get an OpenAI API Key:
   - Create an account on [OpenAI's platform](https://platform.openai.com/signup)
   - Generate an API key from the [API Keys page](https://platform.openai.com/account/api-keys)

2. Configure Environment Variables:
   ```
   OPENAI_API_KEY=your_api_key_here
   ```

3. Enable Billing for OpenAI:
   - Add a payment method to your OpenAI account
   - Set appropriate usage limits to control costs

### Application Configuration

After configuring the external APIs, update your application settings:

1. Clear Configuration Cache:
   ```bash
   php artisan config:clear
   ```

2. Run Database Migrations:
   ```bash
   php artisan migrate
   ```

3. Test the Integration:
   - Access the test page at `/voice-test/index.html` 
   - Verify that both Google and OpenAI integrations are working correctly

## Technical Flow

### 1. Voice Input Capture

- The frontend component records the user's voice using the device's microphone
- Audio is captured in WebM format with Opus codec for optimal quality and size
- The recorded audio is sent to the backend API endpoint `/api/v1/rest/voice-order`

### 2. Speech-to-Text Transcription

- The `VoiceOrderService` processes the audio file using Google Cloud Speech-to-Text
- Custom speech contexts with food-related phrases improve recognition accuracy
- Supported languages include English (US/UK), Arabic, Spanish, French, Hindi, Swahili, and Chinese
- Transcription results are cached to improve performance and reduce API costs

### 3. AI-Powered Intent Analysis

- The transcribed text is processed by the `AIOrderService` using OpenAI's language models
- The system extracts:
  - Primary order intent (e.g., "pizza", "vegetarian meal")
  - Dietary filters (e.g., "vegetarian", "gluten-free")
  - Exclusions or allergens (e.g., "no nuts", "without dairy")
  - Cuisine preferences (e.g., "Italian", "Thai")
  - Spice level preferences
- Context from previous orders is maintained in the same session for conversational ordering

### 4. Product Recommendation

- The `FoodIntelligenceService` takes the processed order intent and applies it to product filtering
- The system:
  - Searches for products matching the primary intent
  - Applies dietary and cuisine filters
  - Excludes allergens and unwanted ingredients
  - Factors in user's order history for personalization
  - Ranks results by popularity and rating
- If insufficient results are found, a fallback search is performed with relaxed criteria

### 5. Response Generation

- The system generates a natural language response explaining the recommendations
- A curated list of recommended products is returned with details
- The session context is saved for follow-up questions or modifications

## User Experience

1. **Voice Recording**:
   - User taps the microphone button and speaks their order
   - Audio is processed in real-time with indicators showing recording status

2. **Order Processing**:
   - System shows the transcribed text to confirm what was heard
   - AI processes the intent and displays the understood preferences

3. **Recommendations**:
   - User receives personalized food recommendations
   - A natural language explanation describes why these items were recommended

4. **Continuation**:
   - User can ask follow-up questions to refine their order
   - Previous context is maintained for a conversational experience

## Usage Limits and Authentication

- Unauthenticated users receive limited functionality
- Free tier users get 3 voice order credits by default
- Premium subscribers have unlimited voice order credits
- Voice order history is saved for authenticated users

## Technical Implementation

- Backend: Laravel PHP framework
- Speech Recognition: Google Cloud Speech-to-Text API
- AI Processing: OpenAI API (GPT models)
- Frontend: JavaScript with WebRTC for audio capture
- Data storage: MySQL database with Redis caching

## API Endpoints

- `POST /api/v1/rest/voice-order` - Process voice order from audio file
- `POST /api/v1/rest/voice-order/feedback` - Submit feedback on recommendations
- `GET /api/v1/rest/voice-order/history` - Get user's voice order history
- `POST /api/v1/rest/voice-order/repeat` - Repeat a previous voice order

## Troubleshooting

### Google Cloud Issues

- **Authentication Errors**: Verify the credentials file path and permissions
- **Quota Limits**: Check Google Cloud console for quota usage and limits
- **Audio Format**: Ensure audio is in a supported format (WebM, WAV, MP3, FLAC)
- **Language Support**: Verify the requested language is supported

### OpenAI API Issues

- **API Key Validation**: Ensure the API key is correctly configured
- **Quota Exceeded**: Check OpenAI billing and usage limits
- **Rate Limiting**: Implement proper error handling for rate limit responses
- **Timeout Issues**: Configure appropriate timeout settings for API calls

### Frontend Issues

- **Microphone Access**: Ensure the site is served over HTTPS
- **Browser Compatibility**: Test with Chrome, Firefox, and Safari
- **Mobile Support**: Test on various mobile devices and browsers

## Error Handling

The system includes robust error handling for various scenarios:
- Audio processing failures
- Transcription errors
- AI service unavailability
- Product recommendation failures

Each error is logged with detailed context to facilitate debugging and improvement.

## Future Improvements

- Support for more languages
- Enhanced context awareness for multi-turn conversations
- Voice-based checkout process
- Integration with delivery tracking
- Accent and dialect adaptation for improved recognition

## Audio Storage

The Voice Order System stores audio recordings in Amazon S3 for the following purposes:

1. **Quality Assurance** - Stored recordings can be used to improve speech recognition quality
2. **Debugging** - In case of issues, original audio can be analyzed to diagnose problems
3. **Training Data** - With proper anonymization, recordings may be used to train custom speech models

### Storage Architecture

1. When a user submits a voice order request, the audio file is:
   - Processed for transcription using Google Cloud Speech-to-Text
   - Stored in Amazon S3 with a unique identifier
   - The S3 URL is recorded in the database along with the transcription results

2. Audio files are organized in S3 using the following path structure:
   ```
   voice-orders/{user_id}/{date}/{session_id}-{timestamp}.{extension}
   ```

3. For privacy and security:
   - Audio files are only stored when the user is authenticated
   - Files can be deleted upon user request or after a set retention period
   - Access to stored files is restricted by IAM policies

### Data Retention and Privacy

Voice recordings are subject to the following data retention policy:

1. Recordings are automatically deleted after 30 days unless needed for quality improvement
2. Users can request deletion of their recordings at any time
3. All data is processed in compliance with applicable privacy regulations 