<?php
// google-callback.php
require_once 'config.php'; // Include config which also starts session and autoloads

// Check if the 'code' GET parameter is set (sent back by Google)
if (isset($_GET['code'])) {
    try {
        // Exchange the authorization code for an access token
        $token = $googleClient->fetchAccessTokenWithAuthCode($_GET['code']);

        // Check if fetching the token was successful
        if (!isset($token['error'])) {
            // Set the access token for the client
            $googleClient->setAccessToken($token['access_token']);

            // Store the token in the session for potential future use (optional)
            $_SESSION['google_access_token'] = $token['access_token'];
            // If you requested offline access, you'll also get a refresh_token here
            // which you should store securely in your database associated with the user
            // if (isset($token['refresh_token'])) {
            //     $_SESSION['google_refresh_token'] = $token['refresh_token'];
            //     // Store $token['refresh_token'] securely in DB
            // }


            // Get user profile information from Google
            $oauth2 = new Google\Service\Oauth2($googleClient);
            $googleUserInfo = $oauth2->userinfo->get();

            // --- User Authentication/Registration Logic ---
            $google_id = $googleUserInfo->getId();
            $email = $googleUserInfo->getEmail();
            $name = $googleUserInfo->getName();
            $picture = $googleUserInfo->getPicture();

            // 1. Check if user exists in your database by Google ID or Email
            // $user = findUserByGoogleId($google_id); // Replace with your DB lookup function
            // if (!$user) {
            //    $user = findUserByEmail($email); // Optional: check by email too
            // }

            // 2. If user exists:
            //    - Update their info (name, picture) if needed.
            //    - Log them in (set session variables).
            //    Example:
            //    $_SESSION['user_id'] = $user['id']; // Your internal user ID
            //    $_SESSION['user_email'] = $email;
            //    $_SESSION['user_name'] = $name;
            //    $_SESSION['user_picture'] = $picture;

            // 3. If user doesn't exist:
            //    - Register them in your database (store Google ID, email, name, picture).
            //    - Log them in (set session variables).
            //    Example:
            //    $newUserId = createUser($google_id, $email, $name, $picture); // Replace with your DB insert function
            //    $_SESSION['user_id'] = $newUserId;
            //    $_SESSION['user_email'] = $email;
            //    $_SESSION['user_name'] = $name;
            //    $_SESSION['user_picture'] = $picture;


            // --- Simplified Example: Store info directly in session (Not recommended for production without DB integration) ---
            // $_SESSION['user_id_google'] = $google_id; // Store Google's ID
            // $_SESSION['user_email'] = $email;
            // $_SESSION['user_name'] = $name;
            // $_SESSION['user_picture'] = $picture;
            // $_SESSION['logged_in'] = true;


            // Redirect to a protected area (e.g., dashboard)
            header('Location: dashboard.php');
            exit();

        } else {
            // Handle error during token exchange
             log_error("Google OAuth Error: " . $token['error_description']);
             header('Location: login.php?error=google_token_error');
             exit();
        }
    } catch (Exception $e) {
        // Handle exceptions during the process
        log_error("Google OAuth Exception: " . $e->getMessage());
        header('Location: login.php?error=google_exception');
        exit();
    }
} elseif (isset($_GET['error'])) {
     // Handle error case where user denied access or other Google error
     log_error("Google OAuth Error (Callback): " . $_GET['error']);
     header('Location: login.php?error=google_denied');
     exit();
} else {
    // Invalid request (no code or error parameter)
    header('Location: login.php');
    exit();
}
