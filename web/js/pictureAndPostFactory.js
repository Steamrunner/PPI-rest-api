angular.module("app").factory("pictureAndPostFactory", function($http)
{   var address = "http://api.piratetimes.net/api/v1/"
    var pictureAndPostFactory = {
        partyList : function(){
            var promise = $http.get(address+"parties/?_format=json&show_defunct=true")
            .then(function(response) {
                //First function handles success
                return response.data;
            }, function(response) {
                //Second function handles error
                console.log("error in acquring party list")
        });  
            return promise
        }, 
        imageList : function(x){
            var promise = $http.get(address+"social/?_format=json&sub_type=I"+"&order_by="+x.sort+"&code="+x.partyCode+"&type="+x.socialPlatform+"&offset="+x.offset)
            .then(function(response) {
                //First function handles success
                return response.data;
            }, function(response) {
                //Second function handles error
                console.log("error in acquring image list")
        });  
            return promise
        }
    };
return pictureAndPostFactory;
});