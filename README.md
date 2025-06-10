# Grafana Reporter

The code works with all types of dashboards even those with repeats but not those with repeats based on grafana rows. Currently working on that.

Goes without saying in order to use the application on your device, ensure php version is 8+ and composer is installed on the device too.

To generate a pdf of a dashboard send a request as below

http://your-laravel-app-url-here/api/pdf/grafana-dashboard-id?apitoken=service-account-token&other-variables-here-such-as-from-and-to-and-vars-and-timezone

To set up your grafana application URL, add it as an environement variable to your .env file:

### GRAFANA_URL=grafana_url_here
