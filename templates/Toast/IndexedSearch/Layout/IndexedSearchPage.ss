
<br><br><br><br><br><br><br><br><br><br><br><br><br><br><br>

<p style="text-align:center">
  <input type="text" id="searchInput" placeholder="Search..." style="font-size: 20px; width: 300px; padding: 5px" />
</p>

<hr>

<div class="margin: 0 auto; width: 1000px;">
  <div style="float: left; width: 500px" id="filtersList"></div>
  <div style="float: left; width: 500px" id="searchResults"></div>
</div>
<div style="clear:both"><br></div>





<script>
    document.addEventListener('DOMContentLoaded', function() {
        var searchInput = document.getElementById('searchInput');
        var searchResults = document.getElementById('searchResults');
        var xhr = new XMLHttpRequest();
      
        searchInput.addEventListener('keyup', function() {
          var searchText = searchInput.value.trim();
      
          if (searchText !== '') {
            // Abort any previous AJAX request
            if (xhr.readyState !== 4) {
              xhr.abort();
            }
    
            var url = window.location.href + '/get_results';        
      
            // Make an AJAX request
            xhr.open('GET', url + '?search=' + encodeURIComponent(searchText), true);
            xhr.onreadystatechange = function() {
              if (xhr.readyState === 4 && xhr.status === 200) {
                var response = JSON.parse(xhr.responseText);
                displayResults(response.results);
                displayFilters(response.filters);
              }
            };
            xhr.send();
          } else {
            // Clear the search results
            searchResults.innerHTML = '';
          }
        });
      
        function displayResults(results){
          searchResults.innerHTML = '';
      
          if (results.length === 0) {
            searchResults.innerHTML = 'No results found.';
          } else {

            var resultsHTML = '';

            for (var i = 0; i < results.length; i++) {
                var result = results[i];
                resultsHTML += '<h6>' + result.title + '<br><a style="font-size: 16px; color: #c0c0c0 !important" href="' + result.link + '">' + result.link + '</a></h6>';
                resultsHTML += '<p></p>';
            }

              searchResults.innerHTML = '<p><b>' + results.length + ' result(s) found</b></p>';
              searchResults.innerHTML += resultsHTML;

          }
        }

        function displayFilters(filters)
        {
          var filtersList = document.getElementById("filtersList");
          filtersList.innerHTML = '';

          filters.forEach(filter => {
            var filterItem = document.createElement("li");
            var filterTitle = document.createElement("strong");
            filterTitle.textContent = filter.label + ": ";
            filterItem.appendChild(filterTitle);
      
            var filterOptions = document.createElement("ul");
            filter.options.forEach(option => {
              var optionItem = document.createElement("li");
              optionItem.textContent = option.value + " (" + option.count + ")";
              filterOptions.appendChild(optionItem);
            });
      
            filterItem.appendChild(filterOptions);
            filtersList.appendChild(filterItem);
          });          
        }

      });
          
</script>